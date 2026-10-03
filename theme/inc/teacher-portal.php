<?php
/** Teacher Portal presentation boundary. @package DelnavazanTheme */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function dzn_theme_teacher_portal_templates() {
	return array( 'page-templates/teacher-portal-home.php', 'page-templates/teacher-portal-account.php', 'page-templates/teacher-portal-onboarding.php', 'page-templates/teacher-portal-preview.php' );
}
function dzn_theme_is_teacher_portal_template() {
	if ( dzn_theme_is_route( 'teacher-portal' ) ) {
		return true;
	}
	foreach ( dzn_theme_teacher_portal_templates() as $template ) { if ( is_page_template( $template ) ) { return true; } }
	return false;
}
function dzn_theme_enqueue_teacher_portal_assets() {
	if ( ! dzn_theme_is_teacher_portal_template() ) { return; }
	$css = get_theme_file_path( 'assets/css/teacher-portal.css' );
	$js  = get_theme_file_path( 'assets/js/teacher-portal.js' );
	wp_enqueue_style( 'delnavazan-teacher-portal', get_theme_file_uri( 'assets/css/teacher-portal.css' ), array( 'delnavazan-theme' ), file_exists( $css ) ? (string) filemtime( $css ) : wp_get_theme()->get( 'Version' ) );
	wp_enqueue_script( 'delnavazan-teacher-portal', get_theme_file_uri( 'assets/js/teacher-portal.js' ), array(), file_exists( $js ) ? (string) filemtime( $js ) : wp_get_theme()->get( 'Version' ), array( 'strategy' => 'defer', 'in_footer' => true ) );
}
add_action( 'wp_enqueue_scripts', 'dzn_theme_enqueue_teacher_portal_assets', 20 );

function dzn_theme_teacher_portal_view_model( $screen ) {
	$allowed = array( 'home', 'account', 'onboarding' );
	$screen = in_array( $screen, $allowed, true ) ? $screen : 'home';
	$model = apply_filters( 'dzn_theme_teacher_portal_view_model', null, $screen, get_queried_object_id() );
	if ( is_array( $model ) && false === ( $model['available'] ?? null ) && $screen === ( $model['screen'] ?? null ) && in_array( $model['state'] ?? null, array( 'not_linked', 'onboarding_required', 'signed_out', 'error' ), true ) ) {
		return $model;
	}
	return dzn_theme_teacher_portal_validate_model( $model, $screen ) ? $model : array( 'available' => false, 'state' => 'error', 'screen' => $screen );
}
/** Validate only the display contract; this establishes no domain truth. */
function dzn_theme_teacher_portal_validate_model( $model, $screen ) {
	if ( ! is_array( $model ) || true !== ( $model['available'] ?? false ) || $screen !== ( $model['screen'] ?? null ) ) { return false; }
	if ( ! dzn_theme_teacher_portal_teacher_valid( $model['teacher'] ?? null ) || ! dzn_theme_teacher_portal_navigation_valid( $model['navigation'] ?? null, $screen ) ) { return false; }
	if ( 'home' === $screen ) {
		return is_array( $model['attention'] ?? null ) && is_array( $model['classes'] ?? null ) && is_array( $model['calendar'] ?? null )
			&& ! array_filter( $model['attention'], static fn( $item ) => ! dzn_theme_teacher_portal_attention_valid( $item ) )
			&& ! array_filter( $model['classes'], static fn( $item ) => ! dzn_theme_teacher_portal_class_valid( $item ) )
			&& ! array_filter( $model['calendar'], static fn( $item ) => ! dzn_theme_teacher_portal_calendar_valid( $item ) );
	}
	if ( 'account' === $screen ) {
		return dzn_theme_teacher_portal_profile_valid( $model['profile'] ?? null )
			&& ( false === ( $model['availability_available'] ?? true ) || dzn_theme_teacher_portal_availability_valid( $model['availability'] ?? null, $model['exceptions'] ?? null ) )
			&& dzn_theme_teacher_portal_statistics_valid( $model['statistics'] ?? null )
			&& in_array( $model['google_state'] ?? null, array( 'not_connected', 'connected', 'needs_attention', 'unavailable' ), true )
			&& in_array( $model['payment_state'] ?? null, array( 'pending_verification', 'confirmed', 'paid', 'unavailable' ), true );
	}
	if ( 'onboarding' !== $screen || ! is_int( $model['current_step'] ?? null ) || $model['current_step'] < 1 || $model['current_step'] > 4 ) { return false; }
	$onboarding = $model['onboarding'] ?? null; $actions = $model['actions'] ?? null;
	return is_array( $onboarding ) && is_array( $actions ) && is_array( $onboarding['profile'] ?? null ) && is_array( $onboarding['availability_rules'] ?? null )
		&& in_array( $onboarding['state'] ?? null, array( 'linked_pending', 'in_progress', 'pending_review', 'returned', 'rejected', 'active', 'offboarded' ), true )
		&& in_array( $onboarding['readiness_state'] ?? null, array( 'not_ready', 'ready' ), true )
		&& is_bool( $onboarding['can_edit'] ?? null ) && is_bool( $onboarding['can_submit'] ?? null )
		&& ! array_filter( array( 'profile', 'availability_profile', 'availability_rule', 'submit' ), static fn( $key ) => ! dzn_theme_teacher_portal_text( $actions[ $key ] ?? null ) );
}
function dzn_theme_teacher_portal_text( $value ) { return is_string( $value ) && '' !== trim( $value ); }
function dzn_theme_teacher_portal_teacher_valid( $teacher ) {
	return is_array( $teacher ) && dzn_theme_teacher_portal_text( $teacher['first_name'] ?? null ) && dzn_theme_teacher_portal_text( $teacher['full_name'] ?? null );
}
function dzn_theme_teacher_portal_navigation_valid( $navigation, $screen ) {
	if ( ! is_array( $navigation ) || count( $navigation ) < 3 ) { return false; }
	$current = 0;
	$seen = array();
	foreach ( $navigation as $item ) {
		if ( ! is_array( $item ) ) { return false; }
		$item_screen = $item['screen'] ?? null;
		if ( ! in_array( $item_screen, array( 'home', 'account', 'onboarding' ), true ) || isset( $seen[ $item_screen ] ) || ! dzn_theme_teacher_portal_text( $item['label'] ?? null ) || ! dzn_theme_teacher_portal_text( $item['url'] ?? null ) || ! is_bool( $item['current'] ?? null ) ) { return false; }
		$seen[ $item_screen ] = true;
		if ( $item['current'] ) { ++$current; if ( $item_screen !== $screen ) { return false; } }
	}
	return 1 === $current && count( $seen ) === 3;
}
function dzn_theme_teacher_portal_profile_valid( $profile ) {
	if ( ! is_array( $profile ) ) { return false; }
	foreach ( array( 'name', 'email', 'timezone', 'timezone_label' ) as $field ) { if ( ! dzn_theme_teacher_portal_text( $profile[ $field ] ?? null ) ) { return false; } }
	return in_array( $profile['calendar'] ?? null, array( 'gregorian', 'persian' ), true )
		&& in_array( $profile['timezone'], timezone_identifiers_list(), true );
}
function dzn_theme_teacher_portal_availability_valid( $availability, $exceptions ) {
	if ( ! is_array( $availability ) || ! $availability || ! is_array( $exceptions ) ) { return false; }
	foreach ( $availability as $day ) {
		if ( ! is_array( $day ) || ! dzn_theme_teacher_portal_text( $day['day'] ?? null ) || ! is_array( $day['blocks'] ?? null ) || ! $day['blocks'] || ! is_string( $day['booked'] ?? null ) ) { return false; }
		foreach ( $day['blocks'] as $block ) { if ( ! dzn_theme_teacher_portal_text( $block ) ) { return false; } }
	}
	foreach ( $exceptions as $exception ) { if ( ! dzn_theme_teacher_portal_text( $exception ) ) { return false; } }
	return true;
}
function dzn_theme_teacher_portal_statistics_valid( $statistics ) {
	if ( ! is_array( $statistics ) ) { return false; }
	foreach ( array( 'active_students', 'lessons_month', 'hours_month', 'upcoming', 'year_total' ) as $field ) { if ( ! dzn_theme_teacher_portal_text( $statistics[ $field ] ?? null ) ) { return false; } }
	return true;
}
function dzn_theme_teacher_portal_attention_valid( $item ) {
	$states = array( 'intro_request', 'student_absence', 'teacher_disruption', 'replacement', 'paid_term_review', 'flexible_term_dates', 'google_problem', 'availability_conflict', 'admin_request' );
	if ( ! is_array( $item ) || ! in_array( $item['state'] ?? null, $states, true ) ) { return false; }
	foreach ( array( 'ref', 'title', 'context', 'message', 'due', 'primary' ) as $field ) { if ( ! dzn_theme_teacher_portal_text( $item[ $field ] ?? null ) ) { return false; } }
	if ( true !== ( $item['action_available'] ?? false ) ) { return false; }
	$markers = array( 'replacement' => 'authorized_replacement', 'paid_term_review' => 'schedule_review_authorized', 'flexible_term_dates' => 'lesson_entitlement_authorized' );
	return ! isset( $markers[ $item['state'] ] ) || true === ( $item[ $markers[ $item['state'] ] ] ?? false );
}
function dzn_theme_teacher_portal_class_valid( $item ) {
	$states = array( 'upcoming', 'starting_soon', 'student_absence', 'replacement', 'intro', 'flexible' );
	if ( ! is_array( $item ) || ! in_array( $item['state'] ?? null, $states, true ) ) { return false; }
	foreach ( array( 'ref', 'time', 'student', 'course', 'progress', 'status', 'student_summary', 'schedule_summary', 'previous_private_note' ) as $field ) { if ( ! dzn_theme_teacher_portal_text( $item[ $field ] ?? null ) ) { return false; } }
	return is_bool( $item['start_available'] ?? null ) && is_bool( $item['details_available'] ?? null );
}
function dzn_theme_teacher_portal_calendar_valid( $item ) {
	return is_array( $item ) && in_array( $item['state'] ?? null, array( 'regular', 'intro', 'replacement', 'student_absence', 'flexible' ), true )
		&& dzn_theme_teacher_portal_text( $item['day'] ?? null ) && dzn_theme_teacher_portal_text( $item['time'] ?? null ) && dzn_theme_teacher_portal_text( $item['label'] ?? null );
}
function dzn_theme_teacher_portal_preview_allowed() {
	return 'production' !== wp_get_environment_type() && is_user_logged_in() && current_user_can( 'edit_theme_options' );
}
function dzn_theme_teacher_portal_demo_model( $screen, $url ) {
	$nav = array(
		array( 'screen' => 'home', 'label' => 'خانهٔ مدرس', 'url' => add_query_arg( 'teacher-view', 'home', $url ), 'current' => 'home' === $screen ),
		array( 'screen' => 'account', 'label' => 'حساب کاربری', 'url' => add_query_arg( 'teacher-view', 'account', $url ), 'current' => 'account' === $screen ),
		array( 'screen' => 'onboarding', 'label' => 'شروع همکاری', 'url' => add_query_arg( 'teacher-view', 'onboarding', $url ), 'current' => 'onboarding' === $screen ),
	);
	$base = array( 'available' => true, 'screen' => $screen, 'is_demo' => true, 'teacher' => array( 'first_name' => 'سارا', 'full_name' => 'سارا نمونه' ), 'navigation' => $nav );
	if ( 'account' === $screen ) {
		return array_merge( $base, array(
			'profile' => array( 'name' => 'سارا نمونه', 'email' => 'teacher@example.invalid', 'mobile' => '+00 000 000 000', 'timezone' => 'Asia/Tehran', 'timezone_label' => 'زمان تهران', 'calendar' => 'persian' ),
			'google_state' => 'needs_attention',
			'availability' => array(
				array( 'day' => 'شنبه', 'blocks' => array( '۱۶:۰۰–۱۸:۰۰', '۱۹:۰۰–۲۱:۰۰' ), 'booked' => '۱۸:۳۰–۱۹:۱۵' ),
				array( 'day' => 'دوشنبه', 'blocks' => array( '۱۷:۰۰–۲۱:۰۰' ), 'booked' => '۱۹:۰۰–۱۹:۴۵' ),
				array( 'day' => 'چهارشنبه', 'blocks' => array( '۱۵:۰۰–۱۸:۰۰' ), 'booked' => '' ),
			),
			'exceptions' => array( '۲ مهر — در دسترس نیست', '۱۲ مهر — ۱۸:۰۰ تا ۲۱:۰۰' ),
			'payment_state' => 'pending_verification',
			'statistics' => array( 'active_students' => '۸', 'lessons_month' => '۲۶', 'hours_month' => '۱۹٫۵', 'upcoming' => '۱۲', 'year_total' => '۱۹۴' ),
		) );
	}
	if ( 'onboarding' === $screen ) { return array_merge( $base, array( 'current_step' => 4 ) ); }
	$attention = array(
		array( 'ref'=>'ATT-01','action_available'=>true,'state' => 'intro_request', 'title' => 'درخواست تازهٔ کلاس آشنایی', 'context' => 'نیلا · سه‌تار', 'message' => 'شنبه ۲۸ شهریور، ۱۸:۳۰ زمان شما · ۱۱:۰۰ زمان هنرجو', 'due' => 'پاسخ تا ۱۸ ساعت دیگر', 'primary' => 'بررسی درخواست', 'secondary' => 'زمان‌های جایگزین' ),
		array( 'ref'=>'ATT-02','action_available'=>true,'state' => 'student_absence', 'title' => 'هنرجو غیبت را اطلاع داده', 'context' => 'آرین · پیانو · امروز ۱۷:۰۰', 'message' => 'این اطلاع به‌تنهایی وضعیت حضور یا جایگزینی را تعیین نمی‌کند.', 'due' => 'اطلاع تازه', 'primary' => 'تأیید مشاهده' ),
		array( 'ref'=>'ATT-03','action_available'=>true,'state' => 'teacher_disruption', 'title' => 'نمی‌توانید در کلاس حاضر شوید؟', 'context' => 'مهسا · سنتور · فردا ۱۹:۰۰', 'message' => 'مشکل را گزارش کنید؛ این پیش‌نمایش کلاس را لغو نمی‌کند.', 'due' => 'پیش از شروع کلاس', 'primary' => 'گزارش مشکل' ),
		array( 'ref'=>'ATT-04','action_available'=>true,'authorized_replacement'=>true,'state' => 'replacement', 'title' => 'جلسهٔ جبرانی مجاز نیاز به زمان دارد', 'context' => 'کیان · تار', 'message' => 'مجوز جایگزینی از مدل نمایشی دریافت شده؛ پوسته آن را ایجاد نمی‌کند.', 'due' => 'تا ۵ مهر', 'primary' => 'انتخاب زمان' ),
		array( 'ref'=>'ATT-05','action_available'=>true,'schedule_review_authorized'=>true,'state' => 'paid_term_review', 'title' => 'برنامهٔ ترم پرداخت‌شده آمادهٔ بازبینی است', 'context' => 'رها · کمانچه · ۱۲ جلسه', 'message' => 'بازبینی ایمنی برنامه است، نه پذیرش دوبارهٔ هنرجو.', 'due' => 'شروع: ۳ مهر', 'primary' => 'برنامه درست است', 'secondary' => 'اصلاح برنامه' ),
		array( 'ref'=>'ATT-06','action_available'=>true,'lesson_entitlement_authorized'=>true,'state' => 'flexible_term_dates', 'title' => '۱۲ جلسه مجاز؛ تاریخ‌ها هنوز تعیین نشده‌اند', 'context' => 'سام · تنبک', 'message' => 'جلسه‌های ترم انعطاف‌پذیر به هماهنگی تاریخ نیاز دارند.', 'due' => 'پیش از شروع ترم', 'primary' => 'چیدن تاریخ‌ها' ),
		array( 'ref'=>'ATT-07','action_available'=>true,'state' => 'google_problem', 'title' => 'اتصال Google نیاز به توجه دارد', 'context' => 'حساب نمایشی', 'message' => 'هیچ اتصال واقعی در این پیش‌نمایش انجام نمی‌شود.', 'due' => 'مشکل فنی', 'primary' => 'مشاهدهٔ راهنما' ),
		array( 'ref'=>'ATT-08','action_available'=>true,'state' => 'availability_conflict', 'title' => 'تداخل با زمان در دسترس', 'context' => 'پنجشنبه ۱۸:۰۰', 'message' => 'یک کلاس نمایشی خارج از بازهٔ ترجیحی دیده می‌شود.', 'due' => 'بررسی تا فردا', 'primary' => 'بررسی تداخل' ),
		array( 'ref'=>'ATT-09','action_available'=>true,'state' => 'admin_request', 'title' => 'درخواست مدیر آموزشگاه', 'context' => 'تکمیل اطلاعات حساب', 'message' => 'منطقهٔ زمانی خود را بازبینی کنید.', 'due' => 'تا ۲ مهر', 'primary' => 'باز کردن حساب' ),
	);
	$classes = array(
		array( 'ref'=>'CLS-01','start_available'=>true,'details_available'=>true,'student_summary'=>'هنرجوی ترم نخست','schedule_summary'=>'امروز ۱۰:۰۰، زمان تهران','previous_private_note'=>'تمرکز روی ریتم آرام','state' => 'upcoming', 'time' => '۱۰:۰۰', 'student' => 'هلیا', 'course' => 'سه‌تار', 'progress' => 'ترم ۱ · جلسهٔ ۲/۱۲', 'status' => 'پیش رو' ),
		array( 'ref'=>'CLS-02','start_available'=>true,'details_available'=>true,'student_summary'=>'خلاصهٔ هنرجوی نمایشی','schedule_summary'=>'زمان قطعی نمایشی در منطقهٔ زمانی مدرس','previous_private_note'=>'تمرکز روی ریتم آرام','state' => 'starting_soon', 'time' => '۱۱:۳۰', 'student' => 'پارسا', 'course' => 'پیانو', 'progress' => 'ترم ۲ · جلسهٔ ۷/۱۲', 'status' => 'به‌زودی شروع می‌شود' ),
		array( 'ref'=>'CLS-03','start_available'=>false,'details_available'=>true,'student_summary'=>'خلاصهٔ هنرجوی نمایشی','schedule_summary'=>'زمان قطعی نمایشی در منطقهٔ زمانی مدرس','previous_private_note'=>'تمرکز روی ریتم آرام','state' => 'student_absence', 'time' => '۱۴:۰۰', 'student' => 'مهتاب', 'course' => 'سنتور', 'progress' => 'ترم ۱ · جلسهٔ ۵/۱۲', 'status' => 'غیبت اطلاع داده شده' ),
		array( 'ref'=>'CLS-04','start_available'=>true,'details_available'=>true,'student_summary'=>'خلاصهٔ هنرجوی نمایشی','schedule_summary'=>'زمان قطعی نمایشی در منطقهٔ زمانی مدرس','previous_private_note'=>'تمرکز روی ریتم آرام','state' => 'replacement', 'time' => '۱۶:۳۰', 'student' => 'نوید', 'course' => 'تار', 'progress' => 'جلسهٔ جایگزین مجاز', 'status' => 'جایگزین' ),
		array( 'ref'=>'CLS-05','start_available'=>true,'details_available'=>true,'student_summary'=>'خلاصهٔ هنرجوی نمایشی','schedule_summary'=>'زمان قطعی نمایشی در منطقهٔ زمانی مدرس','previous_private_note'=>'تمرکز روی ریتم آرام','state' => 'intro', 'time' => '۱۸:۰۰', 'student' => 'ساغر', 'course' => 'کمانچه', 'progress' => 'کلاس آشنایی', 'status' => 'آشنایی' ),
		array( 'ref'=>'CLS-06','start_available'=>true,'details_available'=>true,'student_summary'=>'خلاصهٔ هنرجوی نمایشی','schedule_summary'=>'زمان قطعی نمایشی در منطقهٔ زمانی مدرس','previous_private_note'=>'تمرکز روی ریتم آرام','state' => 'flexible', 'time' => '۲۰:۰۰', 'student' => 'آوا', 'course' => 'دف', 'progress' => 'ترم انعطاف‌پذیر', 'status' => 'زمان هماهنگ‌شده' ),
	);
	return array_merge( $base, array(
		'announcement' => array( 'state' => 'technical', 'title' => 'یادآوری فنی', 'message' => 'پیش از نخستین کلاس، صدا و تنظیمات موسیقی را بررسی کنید.' ),
		'attention' => $attention, 'classes' => $classes,
		'calendar' => array( array( 'day' => 'فردا', 'time' => '۱۷:۰۰', 'label' => 'کلاس معمول · سه‌تار', 'state' => 'regular' ), array( 'day' => 'دوشنبه', 'time' => '۱۸:۳۰', 'label' => 'کلاس آشنایی · پیانو', 'state' => 'intro' ), array( 'day' => 'سه‌شنبه', 'time' => '۲۰:۰۰', 'label' => 'جلسهٔ جایگزین · تار', 'state' => 'replacement' ), array( 'day' => 'چهارشنبه', 'time' => '۱۶:۰۰', 'label' => 'غیبت اطلاع‌داده‌شده · سنتور', 'state' => 'student_absence' ) ),
	) );
}
function dzn_theme_render_teacher_portal( $screen, array $model ) { get_template_part( 'template-parts/teacher-portal/shell', null, array( 'screen' => $screen, 'model' => $model ) ); }
function dzn_theme_teacher_portal_component( $component, array $args = array() ) { get_template_part( 'template-parts/teacher-portal/' . sanitize_key( $component ), null, $args ); }
