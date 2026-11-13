<?php
/**
 * Teacher-facing seven-day coverage clock. All aggregate data and labels come
 * from the shared instrument-coverage view; no Teacher or student identity is
 * exposed. Clicking a segment only prefills the existing availability form.
 */
$m = is_array( $args['model'] ?? null ) ? $args['model'] : array();
$clock = is_array( $m['coverage_clock'] ?? null ) ? $m['coverage_clock'] : array();
$view = '\\Delnavazan\\Platform\\Admin\\View\\InstrumentCoverageClockView';
if ( ! class_exists( $view ) ) {
	echo '<div class="dzn-tp-empty-state" role="status"><h3>ساعت پوشش در دسترس نیست</h3><p>اطلاعات پوشش در حال حاضر خوانده نشد. زمان‌های تدریس شما تغییری نکرده است.</p></div>';
	return;
}
$view::render( $clock, array(
	'scope' => 'teacher',
	'base_url' => home_url( '/teacher-portal/' ),
	'preserve' => array( 'teacher-view' => (string) ( $m['screen'] ?? 'settings' ) ),
) );
