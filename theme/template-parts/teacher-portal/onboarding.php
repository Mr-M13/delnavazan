<?php
/** First-time Teacher setup: one server-authoritative step at a time. */
$model = $args['model'] ?? array();
$onboarding = (array) ( $model['onboarding'] ?? array() );
$profile_complete = 'complete' === (string) ( $onboarding['profile_state'] ?? '' );
$availability_complete = 'complete' === (string) ( $onboarding['availability_state'] ?? '' );
$state = (string) ( $onboarding['state'] ?? 'linked_pending' );
$ready = 'active' === $state && 'ready' === (string) ( $onboarding['readiness_state'] ?? '' );
$can_edit = ! empty( $onboarding['can_edit'] );
$submitted = in_array( $state, array( 'pending_review', 'returned', 'rejected', 'active', 'offboarded' ), true );
$step = (int) ( $model['current_step'] ?? 1 );
if ( $step < 1 || $step > 5 ) { $step = 1; }
if ( ! $can_edit && $submitted ) { $step = 5; }
$max_step = (int) ( $model['max_step'] ?? $step );
if ( $max_step < 1 || $max_step > 5 ) { $max_step = $step; }
$state_labels = array(
	'linked_pending' => 'در حال راه‌اندازی', 'in_progress' => 'در حال تکمیل', 'pending_review' => 'در انتظار بررسی مدیر',
	'returned' => 'نیازمند اصلاح', 'rejected' => 'پذیرفته نشد', 'active' => 'تأییدشده و فعال', 'offboarded' => 'غیرفعال شده',
);
$profile_fields = (array) ( $onboarding['profile'] ?? array() );
$profile_missing = array();
if ( trim( (string) ( $profile_fields['display_name'] ?? '' ) ) === '' ) { $profile_missing[] = 'نام نمایشی'; }
if ( ! is_email( (string) ( $profile_fields['email'] ?? '' ) ) ) { $profile_missing[] = 'ایمیل'; }
if ( ! preg_match( '/^[A-Z]{2}$/', (string) ( $profile_fields['country_code'] ?? '' ) ) ) { $profile_missing[] = 'کشور'; }
if ( ! in_array( (string) ( $profile_fields['timezone'] ?? '' ), timezone_identifiers_list(), true ) ) { $profile_missing[] = 'منطقهٔ زمانی'; }
if ( trim( (string) ( $profile_fields['locale'] ?? '' ) ) === '' ) { $profile_missing[] = 'زبان'; }
if ( ! in_array( (string) ( $profile_fields['calendar_preference'] ?? '' ), array( 'auto', 'gregorian', 'persian' ), true ) ) { $profile_missing[] = 'تقویم'; }
$missing = array();
if ( ! $profile_complete ) { $missing[] = $profile_missing ? 'اطلاعات حساب: ' . implode( '، ', $profile_missing ) : 'اطلاعات و منطقهٔ زمانی'; }
if ( ! $availability_complete ) { $missing[] = 'دست‌کم یک زمان واقعی برای تدریس'; }
$consent_accepted = ! empty( $model['recording_terms']['mandatory_accepted'] ) || ! empty( $model['recording_terms']['unified']['mandatory_accepted'] );
if ( ! $consent_accepted ) { $missing[] = 'رضایت ضبط کلاس، پژوهش و حریم خصوصی (الزامی)'; }
$actions = (array) ( $model['actions'] ?? array() );
$anchors = array( 1 => 'tp-welcome', 2 => 'tp-profile', 3 => 'tp-availability', 4 => 'tp-review', 5 => 'tp-status' );
$step_labels = array( 1 => 'شروع', 2 => 'اطلاعات حساب', 3 => 'زمان‌های تدریس', 4 => 'بازبینی و ارسال', 5 => 'وضعیت' );
$step_digits = array( 1 => '۱', 2 => '۲', 3 => '۳', 4 => '۴', 5 => '۵' );
$portal_url = home_url( '/teacher-portal/' );
$step_url = static function ( int $number ) use ( $portal_url, $anchors ): string {
	return add_query_arg( array( 'teacher-view' => 'onboarding', 'onboarding-step' => $number ), $portal_url ) . '#' . ( $anchors[ $number ] ?? 'tp-welcome' );
};
$step_complete = array( 1 => $step > 1, 2 => $profile_complete, 3 => $availability_complete, 4 => $submitted && ! in_array( $state, array( 'returned', 'rejected' ), true ), 5 => $ready );
$step_available = static function ( int $number ) use ( $max_step, $submitted, $can_edit ): bool {
	if ( $number < 1 || $number > $max_step ) { return false; }
	return $can_edit || ( 5 === $number && $submitted );
};
?>

<section class="dzn-tp-section dzn-tp-account-setup" aria-labelledby="tp-onboarding" id="tp-welcome">
	<div class="dzn-tp-heading">
		<div><p class="dzn-tp-kicker">حساب مدرس دلنوازان</p><h2 id="tp-onboarding">راه‌اندازی حساب شما</h2></div>
		<span><?php echo esc_html( $state_labels[ $state ] ?? 'وضعیت نامشخص' ); ?></span>
	</div>
	<p>راه‌اندازی در چند مرحله انجام می‌شود و هر بار فقط همان مرحله را می‌بینید. اطلاعات هر مرحله جداگانه ذخیره می‌شود؛ می‌توانید به مراحل قبلی برگردید و آن‌ها را اصلاح کنید.</p>
	<nav class="dzn-tp-setup-progress dzn-tp-setup-progress--steps" role="list" aria-label="وضعیت راه‌اندازی حساب">
		<?php for ( $number = 1; $number <= 5; $number++ ) : ?>
			<?php
			$is_current = $number === $step;
			$is_complete = ! empty( $step_complete[ $number ] );
			$is_available = $step_available( $number );
			$class = $is_current ? 'is-current' : ( $is_complete ? 'is-complete' : ( $is_available ? '' : 'is-pending' ) );
			$status = $is_complete ? 'کامل' : ( $is_current ? 'مرحلهٔ جاری' : ( $is_available ? 'در دسترس' : 'در انتظار' ) );
			?>
			<?php if ( $is_available ) : ?>
				<a class="<?php echo esc_attr( $class ); ?>" href="<?php echo esc_url( $step_url( $number ) ); ?>" role="listitem"<?php echo $is_current ? ' aria-current="step"' : ''; ?>><span aria-hidden="true"><?php echo $is_complete ? '✓' : esc_html( $step_digits[ $number ] ); ?></span><strong><?php echo esc_html( $step_labels[ $number ] ); ?></strong><small><?php echo esc_html( $status ); ?></small></a>
			<?php else : ?>
				<span class="<?php echo esc_attr( $class ); ?>" role="listitem"><span aria-hidden="true"><?php echo esc_html( $step_digits[ $number ] ); ?></span><strong><?php echo esc_html( $step_labels[ $number ] ); ?></strong><small><?php echo esc_html( $status ); ?></small></span>
			<?php endif; ?>
		<?php endfor; ?>
	</nav>
	<p class="dzn-tp-help">کلاس‌ها به‌صورت متمرکز توسط دلنوازان در Google Meet ساخته می‌شوند و نیازی به اتصال حساب Google شما نیست.</p>
	<?php if ( isset( $_GET['dzn_notice'] ) ) : ?><p class="dzn-tp-notice <?php echo ( $_GET['dzn_error'] ?? '' ) === '1' ? 'is-error' : 'is-success'; ?>" role="status"><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['dzn_notice'] ) ) ); ?></p><?php endif; ?>
	<?php if ( in_array( $state, array( 'returned', 'rejected' ), true ) ) : ?><div class="dzn-tp-review-note is-warning"><strong>یادداشت بررسی</strong><p><?php echo esc_html( (string) ( $onboarding['review_note'] ?? 'اطلاعات برای اصلاح برگردانده شده است.' ) ); ?></p></div><?php endif; ?>
</section>

<?php if ( $ready ) : ?>
	<section class="dzn-tp-section dzn-tp-status-card is-approved" id="tp-status">
		<h2>حساب شما فعال است</h2><p>راه‌اندازی کامل شده و نسخهٔ جدید پرتال مدرس آمادهٔ استفاده است.</p>
		<p><a class="dzn-button" href="<?php echo esc_url( home_url( '/teacher-portal/' ) ); ?>">رفتن به پرتال مدرس</a></p>
	</section>
<?php elseif ( 1 === $step ) : ?>
	<section class="dzn-tp-section" aria-labelledby="tp-welcome-start">
		<div class="dzn-tp-heading"><div><p class="dzn-tp-kicker">مرحلهٔ ۱ از ۵</p><h2 id="tp-welcome-start">شروع راه‌اندازی</h2></div></div>
		<p>پیش از نخستین کلاس، سه چیز را کامل می‌کنیم:</p>
		<ol class="dzn-tp-list">
			<li>اطلاعات حساب و سازهایی که تدریس می‌کنید.</li>
			<li>زمان‌های واقعی تدریس در برنامهٔ هفتگی.</li>
			<li>رضایت ضبط و حریم خصوصی، سپس ارسال برای بررسی مدیر.</li>
		</ol>
		<p><a class="dzn-button" href="<?php echo esc_url( $step_url( 2 ) ); ?>">شروع راه‌اندازی</a></p>
	</section>
<?php elseif ( 2 === $step ) : ?>
	<?php dzn_theme_teacher_portal_component( 'account', array( 'model' => $model, 'show_recording_terms' => false, 'sections' => array( 'profile', 'instruments' ), 'onboarding_continue' => true ) ); ?>
	<?php if ( $profile_missing ) : ?>
		<section class="dzn-tp-section" aria-labelledby="tp-profile-missing"><div class="dzn-tp-review-note is-warning"><strong>هنوز باید تکمیل شود</strong><ul><?php foreach ( $profile_missing as $item ) : ?><li><?php echo esc_html( $item ); ?></li><?php endforeach; ?></ul></div></section>
	<?php endif; ?>
	<nav class="dzn-tp-setup-nav" aria-label="پیمایش مرحلهٔ اطلاعات حساب">
		<a class="dzn-button dzn-button--secondary" href="<?php echo esc_url( $step_url( 1 ) ); ?>">بازگشت</a>
		<?php if ( $profile_complete ) : ?><a class="dzn-button" href="<?php echo esc_url( $step_url( 3 ) ); ?>">ادامه: زمان‌های تدریس</a><?php endif; ?>
	</nav>
<?php elseif ( 3 === $step ) : ?>
	<?php dzn_theme_teacher_portal_component( 'account', array( 'model' => $model, 'show_recording_terms' => false, 'sections' => array( 'availability' ) ) ); ?>
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
	<nav class="dzn-tp-setup-nav" aria-label="پیمایش مرحلهٔ زمان‌های تدریس">
		<a class="dzn-button dzn-button--secondary" href="<?php echo esc_url( $step_url( 2 ) ); ?>">بازگشت</a>
		<?php if ( $availability_complete ) : ?>
			<a class="dzn-button" href="<?php echo esc_url( $step_url( 4 ) ); ?>">ادامه: بازبینی و ارسال</a>
		<?php else : ?>
			<p class="dzn-tp-help">پس از ثبت دست‌کم یک بازهٔ زمانی، مرحلهٔ بعد باز می‌شود.</p>
		<?php endif; ?>
	</nav>
<?php elseif ( 4 === $step ) : ?>
	<div id="tp-review">
	<?php dzn_theme_teacher_portal_component( 'recording-terms', array( 'recording' => $model['recording_terms'] ?? array() ) ); ?>
	<section class="dzn-tp-section dzn-tp-account-submit" aria-labelledby="tp-submit">
		<div class="dzn-tp-heading"><div><p class="dzn-tp-kicker">مرحلهٔ ۴ از ۵</p><h2 id="tp-submit">بازبینی و ارسال برای بررسی مدیر</h2></div></div>
		<?php if ( $missing ) : ?><div class="dzn-tp-review-note is-warning"><strong>هنوز باید تکمیل شود</strong><ul><?php foreach ( $missing as $item ) : ?><li><?php echo esc_html( $item ); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
		<?php if ( ! empty( $onboarding['can_submit'] ) ) : ?>
			<form method="post" action="<?php echo esc_url( $model['actions']['submit'] ?? '' ); ?>"><?php dzn_theme_nonce_field( 'dzn_teacher_onboarding_submit' ); ?><button class="dzn-button" type="submit">ارسال برای بررسی مدیر</button></form>
		<?php elseif ( 'pending_review' === $state ) : ?>
			<p class="dzn-tp-notice is-success">اطلاعات شما ارسال شده است. پس از بررسی مدیر، دسترسی کامل پرتال فعال می‌شود.</p>
		<?php elseif ( ! $missing ) : ?>
			<p class="dzn-tp-notice is-warning">اطلاعات کامل است، اما ارسال در وضعیت فعلی در دسترس نیست. با مدیر آموزشگاه تماس بگیرید.</p>
		<?php else : ?><p class="dzn-tp-help">پس از تکمیل موارد بالا، دکمهٔ ارسال فعال می‌شود.</p><?php endif; ?>
		<nav class="dzn-tp-setup-nav" aria-label="پیمایش مرحلهٔ بازبینی"><a class="dzn-button dzn-button--secondary" href="<?php echo esc_url( $step_url( 3 ) ); ?>">بازگشت: زمان‌های تدریس</a></nav>
	</section>
	</div>
<?php else : ?>
	<section class="dzn-tp-section dzn-tp-status-card" id="tp-status">
		<div class="dzn-tp-heading"><div><p class="dzn-tp-kicker">مرحلهٔ ۵ از ۵</p><h2>وضعیت راه‌اندازی</h2></div></div>
		<?php if ( 'pending_review' === $state ) : ?>
			<p class="dzn-tp-notice is-success">اطلاعات شما برای بررسی مدیر ارسال شده است. پس از تأیید، دسترسی کامل پرتال فعال می‌شود.</p>
		<?php elseif ( 'returned' === $state ) : ?>
			<p class="dzn-tp-notice is-warning">مدیر آموزشگاه اطلاعات شما را برای اصلاح برگردانده است. موارد یادداشت بررسی را اصلاح کنید و دوباره ارسال کنید.</p>
			<?php if ( $can_edit ) : ?><p><a class="dzn-button" href="<?php echo esc_url( $step_url( 2 ) ); ?>">اصلاح اطلاعات</a></p><?php endif; ?>
		<?php elseif ( 'rejected' === $state ) : ?>
			<p class="dzn-tp-notice is-error">این درخواست پذیرفته نشد. برای بررسی دوباره با مدیر آموزشگاه تماس بگیرید.</p>
		<?php elseif ( 'offboarded' === $state ) : ?>
			<p class="dzn-tp-notice is-error">دسترسی مدرس غیرفعال شده است. برای ادامه با مدیر آموزشگاه تماس بگیرید.</p>
		<?php else : ?>
			<p class="dzn-tp-notice is-success">این حساب فعال است. <a href="<?php echo esc_url( home_url( '/teacher-portal/' ) ); ?>">رفتن به پرتال مدرس</a></p>
		<?php endif; ?>
	</section>
<?php endif; ?>
