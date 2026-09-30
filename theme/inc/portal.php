<?php
/**
 * Student Portal presentation boundary.
 *
 * The Theme renders display-ready arrays only. Authentication, protected reads,
 * mutations and canonical state remain the responsibility of Platform code.
 *
 * @package DelnavazanTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Portal templates owned by this presentation increment.
 *
 * @return array
 */
function dzn_theme_portal_templates() {
	return array(
		'page-templates/student-portal-home.php',
		'page-templates/student-portal-account.php',
		'page-templates/student-portal-preview.php',
	);
}

/**
 * Determine whether the current request uses a Portal template.
 *
 * @return bool
 */
function dzn_theme_is_portal_template() {
	if ( dzn_theme_is_route( 'student-portal' ) || dzn_theme_is_route( 'dashboard' ) ) {
		return true;
	}
	foreach ( dzn_theme_portal_templates() as $template ) {
		if ( is_page_template( $template ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Load Portal assets only for Portal pages.
 */
function dzn_theme_enqueue_portal_assets() {
	if ( ! dzn_theme_is_portal_template() ) {
		return;
	}

	$style_path = get_theme_file_path( 'assets/css/portal.css' );
	$script_path = get_theme_file_path( 'assets/js/portal.js' );
	$version = wp_get_theme()->get( 'Version' );

	wp_enqueue_style(
		'delnavazan-student-portal',
		get_theme_file_uri( 'assets/css/portal.css' ),
		array( 'delnavazan-theme' ),
		file_exists( $style_path ) ? (string) filemtime( $style_path ) : $version
	);

	wp_enqueue_script(
		'delnavazan-student-portal',
		get_theme_file_uri( 'assets/js/portal.js' ),
		array(),
		file_exists( $script_path ) ? (string) filemtime( $script_path ) : $version,
		array( 'strategy' => 'defer', 'in_footer' => true )
	);
}
add_action( 'wp_enqueue_scripts', 'dzn_theme_enqueue_portal_assets', 20 );

/**
 * Obtain a display-ready Portal model from an integration owner.
 *
 * The filter deliberately defaults to null. The Theme never queries Platform
 * storage or infers protected Student truth.
 *
 * @param string $screen home|account.
 * @return array
 */
function dzn_theme_student_portal_view_model( $screen ) {
	$screen = in_array( $screen, array( 'home', 'account' ), true ) ? $screen : 'home';
	$model  = apply_filters(
		'dzn_theme_student_portal_view_model',
		null,
		$screen,
		get_queried_object_id()
	);

	if ( ! is_array( $model ) ) {
		return array(
			'available' => false,
			'screen'    => $screen,
		);
	}

	$model['available'] = true;
	$model['screen']    = $screen;

	return $model;
}

/**
 * Whether synthetic preview data may be rendered on this request.
 *
 * Fake Student data is limited to authenticated Theme administrators outside
 * production. It cannot silently become production truth.
 *
 * @return bool
 */
function dzn_theme_student_portal_preview_allowed() {
	return 'production' !== wp_get_environment_type()
		&& is_user_logged_in()
		&& current_user_can( 'edit_theme_options' );
}

/**
 * Development-only synthetic Portal fixtures.
 *
 * These values are never persisted. They exist solely to exercise Theme
 * components until an authoritative protected-read adapter supplies a model.
 *
 * @param string $screen      home|account.
 * @param string $preview_url Preview page permalink.
 * @return array
 */
function dzn_theme_student_portal_demo_model( $screen, $preview_url ) {
	$screen = in_array( $screen, array( 'home', 'account' ), true ) ? $screen : 'home';
	$navigation = array(
		array(
			'label'   => 'خانه',
			'url'     => add_query_arg( array( 'portal-view' => 'home' ), $preview_url ),
			'current' => 'home' === $screen,
		),
		array(
			'label'   => 'حساب کاربری',
			'url'     => add_query_arg( array( 'portal-view' => 'account' ), $preview_url ),
			'current' => 'account' === $screen,
		),
	);

	$history = array(
		array(
			'date'    => '۲۲ شهریور ۱۴۰۵',
			'title'   => 'سه‌تار با نازنین رستگار',
			'outcome' => 'برگزار شد',
			'tone'    => 'success',
			'detail'  => 'جلسهٔ هفتم ترم جاری',
		),
		array(
			'date'    => '۱۵ شهریور ۱۴۰۵',
			'title'   => 'سه‌تار با نازنین رستگار',
			'outcome' => 'غیبت هنرجو ثبت شده',
			'tone'    => 'muted',
			'detail'  => 'جلسهٔ ششم ترم جاری',
		),
		array(
			'date'    => '۸ شهریور ۱۴۰۵',
			'title'   => 'سه‌تار با نازنین رستگار',
			'outcome' => 'جلسهٔ جبرانی برگزار شد',
			'tone'    => 'information',
			'detail'  => 'جایگزین جلسهٔ تغییرزمان‌یافتهٔ ۲ شهریور',
		),
	);

	$base = array(
		'available'  => true,
		'screen'     => $screen,
		'is_demo'    => true,
		'navigation' => $navigation,
		'student'    => array(
			'first_name' => 'باران',
			'full_name'  => 'باران فرهمند',
		),
	);

	if ( 'account' === $screen ) {
		return array_merge(
			$base,
			array(
				'profile' => array(
					'full_name'      => 'باران فرهمند',
					'email'          => 'baran@example.invalid',
					'mobile'         => '+61 000 000 000',
					'timezone_label' => 'زمان بریزبن',
					'timezone'       => 'Australia/Brisbane',
					'password_url'   => '',
				),
				'history' => $history,
				'payments' => array(
					'status'       => 'نمایش وضعیت اشتراک پس از اتصال امن',
					'renewal_date' => 'تاریخ تمدید هنوز از منبع معتبر دریافت نشده است.',
					'items'        => array(),
				),
				'notifications' => array(
					array( 'date' => '۲۴ شهریور', 'title' => 'یادآوری کلاس فردا', 'message' => 'زمان کلاس بر پایهٔ منطقهٔ زمانی انتخابی شما نمایش داده می‌شود.' ),
					array( 'date' => '۲۰ شهریور', 'title' => 'برنامهٔ ماه مهر', 'message' => 'تقویم آموزشی ماه آینده به‌زودی اعلام می‌شود.' ),
					array( 'date' => '۱ شهریور', 'title' => 'پیام دلنوازان', 'message' => 'از همراهی شما با جمع موسیقی دلنوازان سپاسگزاریم.' ),
				),
				'notification_archive_url' => '',
			)
		);
	}

	return array_merge(
		$base,
		array(
			'announcement' => array(
				'id'      => 'demo-mehr-1405',
				'eyebrow' => 'پیام دلنوازان',
				'title'   => 'برنامهٔ آموزشی ماه مهر',
				'message' => 'برنامهٔ کامل ماه آینده پس از نهایی‌شدن زمان‌ها در همین صفحه نمایش داده می‌شود.',
			),
			'upcoming_lesson' => array(
				'presentation_state' => 'starting_soon',
				'lifecycle_state'    => 'scheduled',
				'schedule_state'     => 'confirmed',
				'attendance_state'   => 'not_recorded',
				'entitlement_state'  => 'standard',
				'course'             => 'سه‌تار — ترم پاییز',
				'teacher'            => 'نازنین رستگار',
				'date'               => 'شنبه ۲۸ شهریور ۱۴۰۵',
				'time'               => '۱۸:۳۰ تا ۱۹:۱۵',
				'timezone_label'     => 'زمان بریزبن',
				'join_url'           => '',
				'notice'             => 'کلاس تا کمتر از یک ساعت دیگر آغاز می‌شود.',
				'owed_session_label' => '',
			),
			'term' => array(
				'title'                   => 'ترم پاییز سه‌تار',
				'completed'               => 7,
				'total'                   => 12,
				'current_label'           => 'جلسهٔ ۸ از ۱۲',
				'next_label'              => 'جلسهٔ بعد: شنبه ۴ مهر',
				'normal_replacement'      => 'یک جلسهٔ جایگزین برنامه‌ریزی شده',
				'academy_owed_remedial'   => 'جلسهٔ بدهکارِ آموزشگاه وجود ندارد',
				'academy_owed_tone'       => 'success',
			),
			'history' => $history,
			'contact' => array(
				'whatsapp_url' => 'https://contact.example.invalid/whatsapp',
				'email_url'    => 'mailto:student-portal@example.invalid',
				'instagram_url'=> 'https://social.example.invalid/instagram',
			),
		)
	);
}

/**
 * Render one Portal screen through its shared shell.
 *
 * @param string $screen home|account.
 * @param array  $model  Display-ready view model.
 */
function dzn_theme_render_student_portal( $screen, array $model ) {
	get_template_part(
		'template-parts/portal/shell',
		null,
		array(
			'screen' => $screen,
			'model'  => $model,
		)
	);
}

/**
 * Render a Portal presentation component.
 *
 * @param string $component Component filename without extension.
 * @param array  $args      Display-ready values escaped by the component.
 */
function dzn_theme_portal_component( $component, array $args = array() ) {
	$component = sanitize_key( $component );
	get_template_part( 'template-parts/portal/' . $component, null, $args );
}
