<?php
/**
 * Template Name: Student Portal — Development Preview
 * Template Post Type: page
 *
 * Authenticated Theme administrators may use synthetic fixtures on a
 * non-production WordPress environment. No fixture is rendered otherwise.
 *
 * @package DelnavazanTheme
 */

get_header();

while ( have_posts() ) {
	the_post();

	if ( ! dzn_theme_student_portal_preview_allowed() ) {
		?>
		<main id="main-content" class="site-main dzn-container" tabindex="-1">
			<?php
			dzn_theme_component(
				'state',
				array(
					'state'   => 'error',
					'title'   => 'پیش‌نمایش در دسترس نیست',
					'message' => 'این نمای آزمایشی فقط برای مدیر پوسته در محیط غیرتولیدی فعال است.',
				)
			);
			?>
		</main>
		<?php
		continue;
	}

	$screen = isset( $_GET['portal-view'] ) ? sanitize_key( wp_unslash( $_GET['portal-view'] ) ) : 'home';
	$screen = in_array( $screen, array( 'home', 'account' ), true ) ? $screen : 'home';
	$model  = dzn_theme_student_portal_demo_model( $screen, get_permalink() );

	if ( 'home' === $screen && isset( $_GET['lesson-state'] ) ) {
		$lesson_state = sanitize_key( wp_unslash( $_GET['lesson-state'] ) );
		$lesson_state_examples = array(
			'upcoming' => array( 'کلاس شما برای زمان اعلام‌شده برنامه‌ریزی شده است.', '' ),
			'starting_soon' => array( 'کلاس تا کمتر از یک ساعت دیگر آغاز می‌شود.', '' ),
			'absence_notified' => array( 'اطلاع غیبت شما دریافت شده است؛ این پیام دربارهٔ تحویل یا حق جلسه داوری نمی‌کند.', '' ),
			'time_changed' => array( 'زمان تازه را با منطقهٔ زمانی انتخابی خود بررسی کنید.', '' ),
			'academy_cancelled' => array( 'این کلاس از سوی مدرس یا آموزشگاه برگزار نمی‌شود.', 'یک جلسه برای برنامه‌ریزی دوباره باقی می‌ماند.' ),
			'awaiting_reschedule' => array( 'زمان جایگزین هنوز اعلام نشده است.', '' ),
			'none' => array( 'پس از دریافت برنامهٔ معتبر، کلاس بعدی اینجا نمایش داده می‌شود.', '' ),
		);

		if ( isset( $lesson_state_examples[ $lesson_state ] ) ) {
			$model['upcoming_lesson']['presentation_state'] = $lesson_state;
			$model['upcoming_lesson']['notice'] = $lesson_state_examples[ $lesson_state ][0];
			$model['upcoming_lesson']['owed_session_label'] = $lesson_state_examples[ $lesson_state ][1];
		}
	}

	dzn_theme_render_student_portal( $screen, $model );
}

get_footer();
