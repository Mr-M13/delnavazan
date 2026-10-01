<?php
$model = $args['model'] ?? array(); $onboarding = $model['onboarding'] ?? array(); $profile = $onboarding['profile'] ?? array(); $rules = $onboarding['availability_rules'] ?? array();
$current = max( 1, min( 4, (int) ( $model['current_step'] ?? 1 ) ) );
$steps = array( 'اطلاعات مدرس', 'منطقهٔ زمانی و دسترسی', 'بررسی مدیر', 'فعال‌سازی' );
$states = array( 'linked_pending' => 'حساب متصل؛ تکمیل اطلاعات لازم است', 'in_progress' => 'در حال تکمیل', 'pending_review' => 'در انتظار بررسی مدیر', 'returned' => 'برای اصلاح بازگردانده شده', 'rejected' => 'رد شده؛ امکان اصلاح و ارسال دوباره وجود دارد', 'active' => 'فعال و آماده', 'offboarded' => 'غیرفعال شده' );
$editable = ! empty( $onboarding['can_edit'] ); $timezone = (string) ( $profile['timezone'] ?? 'Asia/Tehran' );
?>
<section class="dzn-tp-section" aria-labelledby="tp-onboarding">
	<div class="dzn-tp-heading"><div><p class="dzn-tp-kicker">شروع همکاری</p><h2 id="tp-onboarding">آمادگی حساب مدرس</h2></div><span><?php echo esc_html( $states[ $onboarding['state'] ?? '' ] ?? 'وضعیت نامشخص' ); ?></span></div>
	<?php if ( isset( $_GET['dzn_notice'] ) ) : ?><p class="dzn-tp-notice <?php echo ( $_GET['dzn_error'] ?? '' ) === '1' ? 'is-error' : 'is-success'; ?>" role="status"><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['dzn_notice'] ) ) ); ?></p><?php endif; ?>
	<ol class="dzn-tp-onboarding"><?php foreach ( $steps as $index => $step ) : ?><li class="<?php echo esc_attr( $index + 1 < $current ? 'is-complete' : ( $index + 1 === $current ? 'is-current' : 'is-future' ) ); ?>"><?php echo esc_html( $step ); ?></li><?php endforeach; ?></ol>
	<?php if ( in_array( $onboarding['state'] ?? '', array( 'returned', 'rejected' ), true ) && ! empty( $onboarding['review_reason_code'] ) ) : ?><div class="dzn-tp-review-note"><strong>یادداشت مدیر:</strong> <?php echo esc_html( $onboarding['review_reason_code'] ); ?></div><?php endif; ?>
	<?php if ( 'pending_review' === ( $onboarding['state'] ?? '' ) ) : ?><p class="dzn-tp-help">اطلاعات شما ثبت شده و در انتظار بررسی مدیر است. پس از بازگرداندن یا رد شدن می‌توانید اصلاح و دوباره ارسال کنید.</p><?php endif; ?>
	<?php if ( 'active' === ( $onboarding['state'] ?? '' ) && 'ready' === ( $onboarding['readiness_state'] ?? '' ) ) : ?><p class="dzn-tp-notice is-success">حساب شما فعال است و پرتال مدرس آمادهٔ استفاده است.</p><?php endif; ?>
</section>

<section class="dzn-tp-section" aria-labelledby="tp-profile">
	<div class="dzn-tp-heading"><div><p class="dzn-tp-kicker">گام ۱</p><h2 id="tp-profile">اطلاعات مدرس</h2></div><span><?php echo esc_html( 'complete' === ( $onboarding['profile_state'] ?? '' ) ? 'کامل' : 'ناقص' ); ?></span></div>
	<form class="dzn-tp-form" method="post" action="<?php echo esc_url( $model['actions']['profile'] ?? '' ); ?>">
		<?php wp_nonce_field( 'dzn_teacher_onboarding_profile' ); ?>
		<div class="dzn-tp-form-grid">
			<label>نام نمایشی<input required name="display_name" value="<?php echo esc_attr( $profile['display_name'] ?? '' ); ?>" <?php disabled( ! $editable ); ?>></label>
			<label>نام فارسی<input name="persian_name" value="<?php echo esc_attr( $profile['persian_name'] ?? '' ); ?>" <?php disabled( ! $editable ); ?>></label>
			<label>نام انگلیسی<input name="english_name" value="<?php echo esc_attr( $profile['english_name'] ?? '' ); ?>" <?php disabled( ! $editable ); ?>></label>
			<label>ایمیل<input required type="email" name="email" value="<?php echo esc_attr( $profile['email'] ?? '' ); ?>" <?php disabled( ! $editable ); ?>></label>
			<label>کد کشور<input required maxlength="2" name="country_code" value="<?php echo esc_attr( $profile['country_code'] ?? '' ); ?>" placeholder="IR" <?php disabled( ! $editable ); ?>></label>
			<label>شهر (اختیاری)<input name="city" value="<?php echo esc_attr( $profile['city'] ?? '' ); ?>" <?php disabled( ! $editable ); ?>></label>
			<label>منطقهٔ زمانی<select required name="timezone" <?php disabled( ! $editable ); ?>><?php echo wp_timezone_choice( $timezone, get_user_locale() ); ?></select></label>
			<label>زبان<input required name="locale" value="<?php echo esc_attr( $profile['locale'] ?? 'fa_IR' ); ?>" placeholder="fa_IR" <?php disabled( ! $editable ); ?>></label>
			<label>تقویم<select required name="calendar_preference" <?php disabled( ! $editable ); ?>><?php foreach ( array( 'persian' => 'شمسی', 'gregorian' => 'میلادی', 'auto' => 'خودکار' ) as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $profile['calendar_preference'] ?? '', $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
		</div>
		<?php if ( $editable ) : ?><button class="dzn-button" type="submit">ذخیرهٔ اطلاعات</button><?php endif; ?>
	</form>
</section>

<section class="dzn-tp-section" aria-labelledby="tp-availability">
	<div class="dzn-tp-heading"><div><p class="dzn-tp-kicker">گام ۲</p><h2 id="tp-availability">منطقهٔ زمانی و دسترسی هفتگی</h2></div><span><?php echo esc_html( 'complete' === ( $onboarding['availability_state'] ?? '' ) ? 'کامل' : 'حداقل یک بازه لازم است' ); ?></span></div>
	<p class="dzn-tp-help">برای آمادگی نهایی، منطقهٔ زمانی معتبر و دست‌کم یک بازهٔ «ترجیحی» یا «قابل درخواست» ثبت کنید.</p>
	<?php if ( $editable ) : ?>
	<form class="dzn-tp-inline-form" method="post" action="<?php echo esc_url( $model['actions']['availability_profile'] ?? '' ); ?>"><?php wp_nonce_field( 'dzn_teacher_onboarding_availability_profile' ); ?><input type="hidden" name="timezone" value="<?php echo esc_attr( $timezone ); ?>"><button class="dzn-button dzn-button--secondary" type="submit">هماهنگ‌سازی منطقهٔ زمانی با پروفایل</button></form>
	<form class="dzn-tp-form" method="post" action="<?php echo esc_url( $model['actions']['availability_rule'] ?? '' ); ?>">
		<?php wp_nonce_field( 'dzn_teacher_onboarding_availability_rule' ); ?><input type="hidden" name="timezone" value="<?php echo esc_attr( $timezone ); ?>">
		<div class="dzn-tp-form-grid">
			<label>روز هفته<select name="weekday"><?php foreach ( array( 1 => 'دوشنبه', 2 => 'سه‌شنبه', 3 => 'چهارشنبه', 4 => 'پنجشنبه', 5 => 'جمعه', 6 => 'شنبه', 7 => 'یکشنبه' ) as $value => $label ) : ?><option value="<?php echo esc_attr( (string) $value ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
			<label>از<input required type="time" name="local_start_time"></label><label>تا<input required type="time" name="local_end_time"></label>
			<label>نوع بازه<select name="state"><option value="requestable">قابل درخواست</option><option value="preferred">ترجیحی</option></select></label>
		</div><button class="dzn-button" type="submit">افزودن بازه</button>
	</form>
	<?php endif; ?>
	<?php if ( $rules ) : ?><ul class="dzn-tp-rule-list"><?php foreach ( $rules as $rule ) : ?><li><strong><?php echo esc_html( (string) $rule['weekday'] ); ?></strong> — <?php echo esc_html( substr( $rule['local_start_time'], 0, 5 ) . ' تا ' . substr( $rule['local_end_time'], 0, 5 ) ); ?> · <?php echo esc_html( $rule['state'] === 'preferred' ? 'ترجیحی' : ( $rule['state'] === 'requestable' ? 'قابل درخواست' : 'مسدود' ) ); ?></li><?php endforeach; ?></ul><?php else : ?><p>هنوز بازه‌ای ثبت نشده است.</p><?php endif; ?>
</section>

<section class="dzn-tp-section" aria-labelledby="tp-review">
	<div class="dzn-tp-heading"><div><p class="dzn-tp-kicker">گام ۳</p><h2 id="tp-review">توافق‌ها و ارسال برای بررسی</h2></div><span>بررسی مدیر الزامی است</span></div>
	<p class="dzn-tp-help">برای نسخهٔ نخست هیچ مدرک یا توافق تعریف‌شده‌ای در Platform وجود ندارد؛ بنابراین این بخش فعلاً مانع ارسال نیست و وضعیت آن «لازم نیست» ثبت می‌شود.</p>
	<?php if ( $editable ) : ?><form method="post" action="<?php echo esc_url( $model['actions']['submit'] ?? '' ); ?>"><?php wp_nonce_field( 'dzn_teacher_onboarding_submit' ); ?><button class="dzn-button" type="submit" <?php disabled( empty( $onboarding['can_submit'] ) ); ?>>ارسال برای بررسی مدیر</button><?php if ( empty( $onboarding['can_submit'] ) ) : ?><p class="dzn-tp-help">ابتدا اطلاعات و دسترسی هفتگی را کامل کنید.</p><?php endif; ?></form><?php endif; ?>
</section>
