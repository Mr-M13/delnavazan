<?php
/**
 * Teacher account onboarding: welcome, profile, availability, review and staff decision.
 *
 * Presentation only. Every command below posts to a bounded Platform controller; readiness,
 * lifecycle transitions and administrator authority stay in TeacherOnboardingService and
 * TeacherAvailabilityService.
 *
 * @package DelnavazanTheme
 */
$model = $args['model'] ?? array();
$onboarding = $args['model']['onboarding'] ?? array();
$profile = $onboarding['profile'] ?? array();
$rules = array_values( array_filter( (array) ( $onboarding['availability_rules'] ?? array() ), static fn( $rule ) => is_array( $rule ) && in_array( (string) ( $rule['state'] ?? '' ), array( 'preferred', 'requestable' ), true ) ) );
$current = max( 1, min( 5, (int) ( $model['current_step'] ?? 1 ) ) );
$max_step = max( $current, min( 5, (int) ( $model['max_step'] ?? $current ) ) );
$steps = array( 'خوش آمدید', 'اطلاعات شما', 'زمان‌های تدریس', 'بازبینی و ارسال', 'وضعیت بررسی' );
$states = array(
	'linked_pending' => 'حساب متصل؛ تکمیل اطلاعات لازم است',
	'in_progress' => 'در حال تکمیل',
	'pending_review' => 'در انتظار بررسی مدیر',
	'returned' => 'نیازمند اصلاح',
	'rejected' => 'پذیرفته نشد',
	'active' => 'تأییدشده و فعال',
	'offboarded' => 'غیرفعال شده',
);
$review_note = (string) ( $onboarding['review_note'] ?? 'مدیر آموزشگاه درخواست اصلاح ثبت کرده است.' );
$editable = ! empty( $onboarding['can_edit'] );
$timezone = in_array( (string) ( $profile['timezone'] ?? '' ), timezone_identifiers_list(), true ) ? (string) $profile['timezone'] : 'Asia/Tehran';
$profile_complete = 'complete' === ( $onboarding['profile_state'] ?? '' );
$availability_complete = 'complete' === ( $onboarding['availability_state'] ?? '' );
$google_connected = 'connected' === ( $onboarding['google_state'] ?? '' );
$country_options = array( 'IR' => 'ایران', 'AU' => 'استرالیا', 'CA' => 'کانادا', 'US' => 'آمریکا', 'GB' => 'بریتانیا', 'DE' => 'آلمان', 'FR' => 'فرانسه', 'NL' => 'هلند', 'SE' => 'سوئد', 'NZ' => 'نیوزیلند' );
$weekday_labels = array( 1 => 'دوشنبه', 2 => 'سه‌شنبه', 3 => 'چهارشنبه', 4 => 'پنجشنبه', 5 => 'جمعه', 6 => 'شنبه', 7 => 'یکشنبه' );
$weekly_minutes = 0;
foreach ( $rules as $rule ) {
	$start = substr( (string) $rule['local_start_time'], 0, 5 );
	$end = substr( (string) $rule['local_end_time'], 0, 5 );
	$start_minutes = (int) substr( $start, 0, 2 ) * 60 + (int) substr( $start, 3, 2 );
	$end_minutes = '24:00' === $end ? 1440 : ( (int) substr( $end, 0, 2 ) * 60 + (int) substr( $end, 3, 2 ) );
	if ( $end_minutes > $start_minutes ) { $weekly_minutes += $end_minutes - $start_minutes; }
}
$catalogue = (array) ( $model['instruments'] ?? array() );
$selected_courses = array_values( array_filter( $catalogue, static fn( $row ) => (bool) $row->selected ) );
$missing = array();
if ( ! $profile_complete ) { $missing[] = array( 'label' => 'اطلاعات شخصی و حرفه‌ای', 'step' => 2 ); }
if ( ! $availability_complete ) { $missing[] = array( 'label' => 'دست‌کم یک بازهٔ زمان تدریس', 'step' => 3 ); }
if ( ! $google_connected ) { $missing[] = array( 'label' => 'اتصال Google Calendar', 'step' => 4 ); }
$step_url = static fn( int $step ): string => add_query_arg( array( 'teacher-view' => 'onboarding', 'onboarding-step' => $step ), home_url( '/teacher-portal/' ) );
?>
<section class="dzn-tp-section dzn-tp-onboarding-hero" aria-labelledby="tp-onboarding">
	<div class="dzn-tp-heading"><div><p class="dzn-tp-kicker">شروع همکاری · مرحله به مرحله</p><h2 id="tp-onboarding">راه‌اندازی حساب مدرس</h2></div><span><?php echo esc_html( $states[ $onboarding['state'] ?? '' ] ?? 'وضعیت نامشخص' ); ?></span></div>
	<p class="dzn-tp-help">این راه‌اندازی کوتاه است: اطلاعات لازم برای شناسایی و هماهنگی کلاس‌ها را تکمیل می‌کنید، زمان‌های واقعی تدریس را انتخاب می‌کنید و سپس مدیر آموزشگاه حساب را بررسی می‌کند.</p>
	<ol class="dzn-tp-onboarding" aria-label="گام‌های راه‌اندازی"><?php foreach ( $steps as $index => $step ) : ?><li class="<?php echo esc_attr( $index + 1 < $current ? 'is-complete' : ( $index + 1 === $current ? 'is-current' : 'is-future' ) ); ?>"<?php echo $index + 1 === $current ? ' aria-current="step"' : ''; ?>><?php echo esc_html( $step ); ?></li><?php endforeach; ?></ol>
	<?php if ( isset( $_GET['dzn_notice'] ) ) : ?><p class="dzn-tp-notice <?php echo ( $_GET['dzn_error'] ?? '' ) === '1' ? 'is-error' : 'is-success'; ?>" role="status"><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['dzn_notice'] ) ) ); ?></p><?php endif; ?>
</section>

<?php if ( $current === 1 ) : ?>
<section class="dzn-tp-section" aria-labelledby="tp-welcome">
	<div class="dzn-tp-heading"><div><p class="dzn-tp-kicker">گام ۱</p><h2 id="tp-welcome">خوش آمدید به دلنوازان</h2></div><span>شروع</span></div>
	<div class="dzn-tp-welcome-grid">
		<div class="dzn-tp-welcome-card"><h3>دلنوازان چیست؟</h3><p>دلنوازان محل هماهنگی و برگزاری کلاس‌های موسیقی آنلاین است. حساب مدرس، هویت شما، زمان‌های تدریس و ارتباط با هنرجویان را در یک پرتال جمع می‌کند.</p></div>
		<div class="dzn-tp-welcome-card"><h3>پرتال مدرس چه کار می‌کند؟</h3><p>کلاس‌های امروز و آینده، تقویم، درخواست‌های هنرجو، تغییر زمان، جلسات جبرانی، اعلان‌ها، تنظیمات و اطلاعات مالی را نشان می‌دهد.</p></div>
		<div class="dzn-tp-welcome-card"><h3>چه اطلاعاتی لازم است؟</h3><p>نام نمایشی، ایمیل حساب، کشور، منطقهٔ زمانی، ترجیح تقویم و دست‌کم یک بازهٔ هفتگی واقعی برای تدریس. اتصال Google Calendar هم بخشی از آمادگی حساب است.</p></div>
		<div class="dzn-tp-welcome-card"><h3>پس از ارسال چه می‌شود؟</h3><p>اطلاعات شما برای بررسی مدیر آموزشگاه ارسال می‌شود. تا تأیید، دسترسی عادی پرتال فعال نمی‌شود. اگر چیزی نیاز به اصلاح داشته باشد، به همین صفحه برمی‌گردد.</p></div>
	</div>
	<div class="dzn-tp-review-note"><strong>مهم:</strong><p>تأیید حساب و دسترسی نهایی همیشه با مدیر آموزشگاه است. تکمیل این فرم به‌تنهایی حساب را فعال نمی‌کند.</p></div>
	<p><a class="dzn-button" href="<?php echo esc_url( $step_url( 2 ) ); ?>">شروع تکمیل اطلاعات</a></p>
</section>
<?php endif; ?>

<?php if ( $current === 2 ) : ?>
<section class="dzn-tp-section" aria-labelledby="tp-profile">
	<div class="dzn-tp-heading"><div><p class="dzn-tp-kicker">گام ۲</p><h2 id="tp-profile">اطلاعات شخصی و حرفه‌ای</h2></div><span><?php echo esc_html( $profile_complete ? 'کامل' : 'ناقص' ); ?></span></div>
	<form class="dzn-tp-form" method="post" action="<?php echo esc_url( $model['actions']['profile'] ?? '' ); ?>">
		<?php dzn_theme_nonce_field( 'dzn_teacher_onboarding_profile' ); ?>
		<p class="dzn-tp-help">این اطلاعات برای نمایش نام شما، تماس‌های ضروری، تبدیل زمان کلاس‌ها و نمایش تقویم در منطقهٔ زمانی خودتان استفاده می‌شود. فیلدهای اختیاری با «اختیاری» مشخص شده‌اند.</p>
		<div class="dzn-tp-form-grid">
			<label>نام نمایشی<input required maxlength="191" name="display_name" value="<?php echo esc_attr( $profile['display_name'] ?? '' ); ?>" autocomplete="name" <?php disabled( ! $editable ); ?>></label>
			<label>نام فارسی (اختیاری)<input maxlength="191" name="persian_name" value="<?php echo esc_attr( $profile['persian_name'] ?? '' ); ?>" <?php disabled( ! $editable ); ?>></label>
			<label>نام انگلیسی (اختیاری)<input maxlength="191" name="english_name" value="<?php echo esc_attr( $profile['english_name'] ?? '' ); ?>" dir="ltr" <?php disabled( ! $editable ); ?>></label>
			<label>ایمیل حساب<input required readonly type="email" name="email" value="<?php echo esc_attr( $profile['email'] ?? '' ); ?>" autocomplete="email"><span class="dzn-tp-help">ایمیل باید با حساب متصل یکسان باشد.</span></label>
			<label>شمارهٔ موبایل (اختیاری)<input type="tel" inputmode="tel" autocomplete="tel" name="phone" value="<?php echo esc_attr( $profile['phone'] ?? '' ); ?>" <?php disabled( ! $editable ); ?>></label>
			<label>شمارهٔ واتس‌اپ (اختیاری)<input type="tel" inputmode="tel" autocomplete="tel" name="whatsapp_phone" value="<?php echo esc_attr( $profile['whatsapp_phone'] ?? '' ); ?>" <?php disabled( ! $editable ); ?>></label>
			<label>کشور<select required name="country_code" <?php disabled( ! $editable ); ?>><option value="">انتخاب کنید</option><?php foreach ( $country_options as $code => $label ) : ?><option value="<?php echo esc_attr( $code ); ?>" <?php selected( $profile['country_code'] ?? '', $code ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select><span class="dzn-tp-help">برای پیشنهاد ساعت مناسب هنرجویان و تنظیم منطقهٔ زمانی.</span></label>
			<label>شهر (اختیاری)<input maxlength="191" name="city" value="<?php echo esc_attr( $profile['city'] ?? '' ); ?>" <?php disabled( ! $editable ); ?>></label>
			<label>منطقهٔ زمانی<select required name="timezone" <?php disabled( ! $editable ); ?>><?php echo wp_timezone_choice( $timezone, get_user_locale() ); ?></select></label>
			<label>زبان پرتال<select required name="locale" <?php disabled( ! $editable ); ?>><option value="fa_IR" <?php selected( $profile['locale'] ?? 'fa_IR', 'fa_IR' ); ?>>فارسی</option><option value="en_AU" <?php selected( $profile['locale'] ?? '', 'en_AU' ); ?>>English</option></select></label>
			<label>تقویم<select required name="calendar_preference" <?php disabled( ! $editable ); ?>><?php foreach ( array( 'persian' => 'شمسی', 'gregorian' => 'میلادی', 'auto' => 'خودکار' ) as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $profile['calendar_preference'] ?? '', $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
		</div>
		<?php if ( $editable ) : ?><div class="dzn-tp-actions"><button class="dzn-button" type="submit" name="onboarding_continue" value="1">ذخیره و ادامه</button><button class="dzn-button dzn-button--secondary" type="submit">ذخیره</button></div><?php else : ?><p class="dzn-tp-help">این اطلاعات در وضعیت فعلی قابل ویرایش نیست. اگر نیاز به اصلاح دارید، وضعیت بررسی را ببینید.</p><?php endif; ?>
	</form>
</section>
<p class="dzn-tp-step-nav"><a class="dzn-button dzn-button--secondary" href="<?php echo esc_url( $step_url( 1 ) ); ?>">بازگشت</a></p>
<?php endif; ?>

<?php if ( $current === 3 ) : ?>
<section class="dzn-tp-section" aria-labelledby="tp-availability">
	<div class="dzn-tp-heading"><div><p class="dzn-tp-kicker">گام ۳</p><h2 id="tp-availability">زمان‌های در دسترس برای تدریس</h2></div><span><?php echo esc_html( $availability_complete ? 'کامل' : 'حداقل یک بازه لازم است' ); ?></span></div>
	<p class="dzn-tp-help">فقط زمان‌هایی را انتخاب کنید که واقعاً برای تدریس در دسترس هستید. ساعت‌های انتخاب‌نشده غیرقابل رزرو می‌مانند. این زمان‌ها تکرار هفتگی دارند و انتخاب آن‌ها تضمین رزرو نیست.</p>
	<p class="dzn-tp-help">منطقهٔ زمانی شما <strong dir="ltr"><?php echo esc_html( $timezone ); ?></strong> است. <a href="<?php echo esc_url( $step_url( 2 ) ); ?>">تغییر منطقهٔ زمانی در پروفایل</a>. روزهای تعطیل و استثناها می‌توانند تقویم هفتگی را برای یک تاریخ مشخص محدود کنند. ویرایش این زمان‌ها، کلاس‌های رزروشده را جابه‌جا نمی‌کند.</p>
	<?php if ( $editable ) : ?>
	<?php $catalogue = (array) ( $model['instruments'] ?? array() ); if ( $catalogue ) : ?><form class="dzn-tp-form" method="post" action="<?php echo esc_url( $model['actions']['instruments'] ?? '' ); ?>"><?php dzn_theme_nonce_field( 'dzn_teacher_instruments' ); ?><fieldset><legend>سازهای مورد تدریس از فهرست آکادمی</legend><?php $groups = array(); foreach ( $catalogue as $row ) { $groups[ (int) $row->instrument_id ][] = $row; } foreach ( $groups as $id => $rows ) : ?><label><input type="checkbox" name="instrument_ids[]" value="<?php echo (int) $id; ?>" <?php checked( (bool) array_filter( $rows, static fn( $r ) => (bool) $r->selected ) ); ?>> <?php echo esc_html( $rows[0]->instrument ); ?></label><?php endforeach; ?></fieldset><button class="dzn-button dzn-button--secondary" type="submit">ذخیرهٔ سازها</button></form><?php endif; ?>
	<form class="dzn-tp-inline-form" method="post" action="<?php echo esc_url( $model['actions']['availability_profile'] ?? '' ); ?>"><?php dzn_theme_nonce_field( 'dzn_teacher_onboarding_availability_profile' ); ?><input type="hidden" name="timezone" value="<?php echo esc_attr( $timezone ); ?>"><button class="dzn-button dzn-button--secondary" type="submit">هماهنگ‌سازی منطقهٔ زمانی با پروفایل</button></form>
	<form class="dzn-tp-form" method="post" action="<?php echo esc_url( $model['actions']['availability_rule'] ?? '' ); ?>">
		<?php dzn_theme_nonce_field( 'dzn_teacher_onboarding_availability_rule' ); ?><input type="hidden" name="timezone" value="<?php echo esc_attr( $timezone ); ?>">
		<div class="dzn-tp-form-grid">
			<label>روز هفته<select name="weekday"><?php foreach ( $weekday_labels as $value => $label ) : ?><option value="<?php echo esc_attr( (string) $value ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
			<label>از<input required inputmode="numeric" pattern="(?:[01][0-9]|2[0-3])[0-5][0-9]" maxlength="4" placeholder="0600" name="local_start_time" dir="ltr" autocomplete="off" aria-describedby="tp-time-help"><span class="dzn-tp-help">چهار رقم ۲۴ ساعته، بدون دونقطه؛ مثال: 0600</span></label>
			<label>تا<input required inputmode="numeric" pattern="(?:[01][0-9]|2[0-3])[0-5][0-9]|2400" maxlength="4" placeholder="1200 یا 2400" name="local_end_time" dir="ltr" autocomplete="off" aria-describedby="tp-time-help"><span class="dzn-tp-help">برای پایان روز 2400 را وارد کنید.</span></label>
			<p id="tp-time-help" class="dzn-tp-help">زمان‌ها به منطقهٔ زمانی <span dir="ltr"><?php echo esc_html( $timezone ); ?></span> ثبت می‌شوند و تبدیل نهایی در سرور انجام می‌شود.</p>
		</div><button class="dzn-button" type="submit">افزودن بازه</button>
	</form>
	<?php dzn_theme_teacher_portal_component( 'golden-hours', array( 'model' => $model, 'show_cta' => false ) ); ?>
	<div class="dzn-tp-review-note"><strong>خلاصهٔ هفتگی</strong><p><?php echo esc_html( number_format_i18n( $weekly_minutes / 60, 1 ) ); ?> ساعت در هفته انتخاب کرده‌اید. ظرفیت تقریبی با احتساب مدت درس و فاصلهٔ بین کلاس‌ها محاسبه می‌شود:</p><?php if ( $selected_courses ) : ?><ul><?php foreach ( $selected_courses as $course ) : $occupied = (int) $course->default_duration_minutes + (int) $course->default_buffer_minutes; $estimate = $occupied > 0 ? (int) floor( $weekly_minutes / $occupied ) : 0; ?><li><?php echo esc_html( $course->instrument ); ?>: حدود <?php echo esc_html( number_format_i18n( $estimate ) ); ?> کلاس در هفته (برآورد؛ <?php echo (int) $course->default_duration_minutes; ?> دقیقه درس + <?php echo (int) $course->default_buffer_minutes; ?> دقیقه فاصله).</li><?php endforeach; ?></ul><?php else : ?><p>برای برآورد ظرفیت، دست‌کم یک ساز قابل تدریس انتخاب کنید.</p><?php endif; ?></div>
	<form class="dzn-tp-form" method="post" action="<?php echo esc_url( $model['actions']['copy_day'] ?? '' ); ?>"><?php dzn_theme_nonce_field( 'dzn_teacher_onboarding_copy_day' ); ?><p><strong>کپی یک روز به روزهای دیگر</strong> — روز مبدأ و مقصدها را انتخاب کنید. اگر مقصدی تداخل داشته باشد، هیچ تغییری انجام نمی‌شود.</p><label>روز مبدأ<select name="source_weekday"><?php foreach ( $weekday_labels as $day => $label ) : ?><option value="<?php echo esc_attr( (string) $day ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><fieldset><legend>روزهای مقصد</legend><?php foreach ( $weekday_labels as $day => $label ) : ?><label><input type="checkbox" name="target_weekdays[]" value="<?php echo esc_attr( (string) $day ); ?>"> <?php echo esc_html( $label ); ?></label> <?php endforeach; ?></fieldset><button class="dzn-button dzn-button--secondary" type="submit">پیش‌نمایش مقصدها و اعمال کپی</button></form>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php?action=dzn_teacher_day_off' ) ); ?>"><?php dzn_theme_nonce_field( 'dzn_teacher_day_off' ); ?><label>روز تعطیل (به وقت <span dir="ltr"><?php echo esc_html( $timezone ); ?></span>) <input type="date" name="local_date" required></label><button class="dzn-button dzn-button--secondary" type="submit">ثبت روز تعطیل</button></form>
	<?php if ( ! empty( $model['day_offs'] ) ) : ?><h3>روزهای تعطیل پیش رو</h3><ul class="dzn-tp-rule-list"><?php foreach ( $model['day_offs'] as $day ) : ?><li><?php echo esc_html( $day->local_date . ' · ' . $day->timezone ); ?></li><?php endforeach; ?></ul><?php endif; ?>
	<?php else : ?><p class="dzn-tp-help">زمان‌های تدریس در وضعیت فعلی قابل ویرایش نیست.</p><?php endif; ?>
	<?php if ( $rules ) : ?><h3>زمان‌های انتخاب‌شده</h3><ul class="dzn-tp-rule-list"><?php foreach ( $rules as $rule ) : ?><li><strong><?php echo esc_html( $weekday_labels[ (int) $rule['weekday'] ] ?? (string) $rule['weekday'] ); ?></strong> — <bdi><?php echo esc_html( str_replace( ':', '', substr( $rule['local_start_time'], 0, 5 ) ) . ' تا ' . ( substr( $rule['local_end_time'], 0, 5 ) === '24:00' ? '2400' : str_replace( ':', '', substr( $rule['local_end_time'], 0, 5 ) ) ) ); ?></bdi></li><?php endforeach; ?></ul><?php else : ?><p>هنوز بازه‌ای ثبت نشده است.</p><?php endif; ?>
</section>
<p class="dzn-tp-step-nav"><a class="dzn-button dzn-button--secondary" href="<?php echo esc_url( $step_url( 2 ) ); ?>">بازگشت</a> <?php if ( $max_step >= 4 ) : ?><a class="dzn-button" href="<?php echo esc_url( $step_url( 4 ) ); ?>">بعدی: بازبینی و ارسال</a><?php endif; ?></p>
<?php endif; ?>

<?php if ( $current === 4 ) : ?>
<section class="dzn-tp-section" aria-labelledby="tp-review">
	<div class="dzn-tp-heading"><div><p class="dzn-tp-kicker">گام ۴</p><h2 id="tp-review">بازبینی و ارسال</h2></div><span>بررسی مدیر</span></div>
	<p class="dzn-tp-help">اطلاعات زیر را بازبینی کنید. تا کامل‌شدن همهٔ موارد لازم، دکمهٔ ارسال فعال نمی‌شود. اتصال Google Calendar بخشی از سیاست آمادگی فعلی است، چون ساخت رویداد تقویم و پیوند Meet پیش از تأیید کلاس به آن وابسته است.</p>
	<div class="dzn-tp-summary-grid">
		<section class="dzn-tp-summary-card"><h3>اطلاعات شخصی</h3><dl><dt>نام نمایشی</dt><dd><?php echo esc_html( $profile['display_name'] ?? '' ); ?></dd><dt>ایمیل</dt><dd dir="ltr"><?php echo esc_html( $profile['email'] ?? '' ); ?></dd><dt>کشور / شهر</dt><dd><?php echo esc_html( trim( ( $country_options[ $profile['country_code'] ?? '' ] ?? (string) ( $profile['country_code'] ?? '' ) ) . ( ! empty( $profile['city'] ) ? ' · ' . $profile['city'] : '' ) ) ); ?></dd><dt>منطقهٔ زمانی</dt><dd dir="ltr"><?php echo esc_html( $timezone ); ?></dd><dt>زبان / تقویم</dt><dd><?php echo esc_html( ( 'en_AU' === ( $profile['locale'] ?? '' ) ? 'English' : 'فارسی' ) . ' · ' . ( array( 'persian' => 'شمسی', 'gregorian' => 'میلادی', 'auto' => 'خودکار' )[ $profile['calendar_preference'] ?? 'auto' ] ?? 'خودکار' ) ); ?></dd></dl><p><a href="<?php echo esc_url( $step_url( 2 ) ); ?>">ویرایش اطلاعات</a></p></section>
		<section class="dzn-tp-summary-card"><h3>زمان‌های تدریس</h3><p><strong><?php echo esc_html( number_format_i18n( $weekly_minutes / 60, 1 ) ); ?></strong> ساعت در هفته در <strong><?php echo esc_html( (string) count( $rules ) ); ?></strong> بازه.</p><p class="dzn-tp-help">زمان‌ها تکرار هفتگی دارند و کلاس‌های رزروشده را جابه‌جا نمی‌کنند.</p><p><a href="<?php echo esc_url( $step_url( 3 ) ); ?>">ویرایش زمان‌ها</a></p></section>
		<section class="dzn-tp-summary-card"><h3>اتصال Google Calendar</h3><p>وضعیت: <strong><?php echo esc_html( $google_connected ? 'متصل' : ( 'unavailable' === ( $model['google_state'] ?? '' ) ? 'در دسترس نیست' : 'متصل نیست' ) ); ?></strong></p><?php if ( $google_connected ) : ?><p class="dzn-tp-notice is-success">اتصال Google آماده است. اتصال حساب، به‌تنهایی اثبات کارکرد Meet نیست.</p><?php elseif ( 'unavailable' === ( $model['google_state'] ?? '' ) ) : ?><p class="dzn-tp-notice is-error">تنظیمات اتصال Google در دسترس نیست. برای تکمیل راه‌اندازی با مدیر سامانه تماس بگیرید.</p><?php else : ?><form method="post" action="<?php echo esc_url( $model['google_action_url'] ?? '' ); ?>"><input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $model['google_nonce'] ?? '' ); ?>"><button class="dzn-button dzn-button--secondary" type="submit">اتصال Google Calendar</button></form><?php endif; ?><?php if ( $google_connected ) : ?><form method="post" action="<?php echo esc_url( $model['google_disconnect_url'] ?? '' ); ?>"><input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $model['google_disconnect_nonce'] ?? '' ); ?>"><button class="dzn-link-button" type="submit">قطع اتصال</button></form><?php endif; ?></section>
	</div>
	<?php if ( $missing ) : ?><div class="dzn-tp-review-note is-warning"><strong>موارد باقی‌مانده</strong><ul><?php foreach ( $missing as $item ) : ?><li><a href="<?php echo esc_url( $step_url( (int) $item['step'] ) ); ?>"><?php echo esc_html( $item['label'] ); ?></a></li><?php endforeach; ?></ul></div><?php endif; ?>
	<?php if ( ! empty( $onboarding['can_submit'] ) ) : ?><form method="post" action="<?php echo esc_url( $model['actions']['submit'] ?? '' ); ?>"><?php dzn_theme_nonce_field( 'dzn_teacher_onboarding_submit' ); ?><button class="dzn-button" type="submit">ارسال برای بررسی مدیر</button></form><?php elseif ( 'pending_review' === ( $onboarding['state'] ?? '' ) ) : ?><p class="dzn-tp-notice is-success">ارسال شده است؛ منتظر نتیجهٔ بررسی مدیر بمانید.</p><?php elseif ( 'active' === ( $onboarding['state'] ?? '' ) && 'ready' === ( $onboarding['readiness_state'] ?? '' ) ) : ?><p class="dzn-tp-notice is-success">حساب شما فعال است و نیازی به ارسال دوباره ندارد.</p><?php else : ?><p class="dzn-tp-notice is-warning">پس از کامل‌شدن موارد بالا، دکمهٔ ارسال فعال می‌شود.</p><?php endif; ?>
</section>
<p class="dzn-tp-step-nav"><a class="dzn-button dzn-button--secondary" href="<?php echo esc_url( $step_url( 3 ) ); ?>">بازگشت</a> <?php if ( $max_step >= 5 || 'pending_review' === ( $onboarding['state'] ?? '' ) ) : ?><a class="dzn-button" href="<?php echo esc_url( $step_url( 5 ) ); ?>">مشاهدهٔ وضعیت بررسی</a><?php endif; ?></p>
<?php endif; ?>

<?php if ( $current === 5 ) : ?>
<section class="dzn-tp-section" aria-labelledby="tp-status">
	<div class="dzn-tp-heading"><div><p class="dzn-tp-kicker">گام ۵</p><h2 id="tp-status">وضعیت بررسی حساب</h2></div><span><?php echo esc_html( $states[ $onboarding['state'] ?? '' ] ?? 'وضعیت نامشخص' ); ?></span></div>
	<?php if ( 'pending_review' === ( $onboarding['state'] ?? '' ) ) : ?><div class="dzn-tp-status-card is-pending"><h3>در انتظار بررسی مدیر</h3><p>درخواست شما ثبت شده و مدیر آموزشگاه آن را بررسی می‌کند. تا اعلام نتیجه، نیازی به ارسال دوباره نیست. پس از تأیید، دسترسی پرتال و راهنمای تدریس باز می‌شود.</p></div>
	<?php elseif ( 'returned' === ( $onboarding['state'] ?? '' ) ) : ?><div class="dzn-tp-status-card is-returned"><h3>نیازمند اصلاح</h3><p><?php echo esc_html( $review_note ); ?></p><p>اطلاعات شما برای اصلاح برگردانده شده است. موارد ناقص را کامل کنید و دوباره ارسال کنید. اطلاعات قبلی حفظ شده است.</p><div class="dzn-tp-actions"><a class="dzn-button" href="<?php echo esc_url( $step_url( 2 ) ); ?>">اصلاح اطلاعات</a><a class="dzn-button dzn-button--secondary" href="<?php echo esc_url( $step_url( 3 ) ); ?>">اصلاح زمان‌های تدریس</a></div></div>
	<?php elseif ( 'rejected' === ( $onboarding['state'] ?? '' ) ) : ?><div class="dzn-tp-status-card is-rejected"><h3>این درخواست پذیرفته نشد</h3><p>حساب مدرس فعال نشد. برای پیگیری یا شروع فرایند دعوت مجدد کنترل‌شده با مدیر آموزشگاه تماس بگیرید. ساخت حساب یا دعوت تازه از این صفحه انجام نمی‌شود.</p></div>
	<?php elseif ( 'active' === ( $onboarding['state'] ?? '' ) && 'ready' === ( $onboarding['readiness_state'] ?? '' ) ) : ?><div class="dzn-tp-status-card is-approved"><h3>حساب شما تأیید و فعال شد</h3><p>اکنون می‌توانید از پرتال مدرس استفاده کنید. راهنمای تدریس دلنوازان را از همان‌جا ادامه دهید؛ تکمیل راهنما برای دسترسی لازم نیست.</p><div class="dzn-tp-actions"><a class="dzn-button" href="<?php echo esc_url( home_url( '/teacher-portal/' ) ); ?>">رفتن به پرتال مدرس</a><a class="dzn-button dzn-button--secondary" href="<?php echo esc_url( add_query_arg( array( 'teacher-view' => 'induction' ), home_url( '/teacher-portal/' ) ) ); ?>">شروع راهنمای تدریس</a></div></div>
	<?php elseif ( 'offboarded' === ( $onboarding['state'] ?? '' ) ) : ?><div class="dzn-tp-status-card is-rejected"><h3>دسترسی غیرفعال شده است</h3><p>برای پیگیری با مدیر آموزشگاه تماس بگیرید.</p></div>
	<?php else : ?><div class="dzn-tp-status-card"><h3>درخواست هنوز ارسال نشده است</h3><p>برای بررسی مدیر، ابتدا اطلاعات و زمان‌های تدریس را کامل کنید و از گام بازبینی ارسال کنید.</p><div class="dzn-tp-actions"><a class="dzn-button" href="<?php echo esc_url( $step_url( 4 ) ); ?>">رفتن به بازبینی</a></div></div><?php endif; ?>
</section>
<p class="dzn-tp-step-nav"><a class="dzn-button dzn-button--secondary" href="<?php echo esc_url( $step_url( 4 ) ); ?>">بازگشت به بازبینی</a></p>
<?php endif; ?>

<?php dzn_theme_teacher_portal_component( 'recording-terms', array( 'recording' => $model['recording_terms'] ?? array() ) ); ?>
