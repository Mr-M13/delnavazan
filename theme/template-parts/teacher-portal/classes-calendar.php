<?php
/**
 * Teacher month calendar: a month grid that marks every day with a class. Selecting a day shows
 * only that day's classes underneath. The day list is rendered for the month and switched in place
 * by the portal script, so choosing a day never reloads the page; the links still work as normal
 * navigation when JavaScript is unavailable.
 *
 * The month (`tp-month`) and the selected day (`tp-day`) are query arguments and are validated
 * here; both are rendered in the Teacher's own calendar (Persian or Gregorian).
 */
$all_items      = is_array( $args['all_items'] ?? null ) ? $args['all_items'] : array();
$model          = is_array( $args['model'] ?? null ) ? $args['model'] : array();
$context        = dzn_theme_teacher_portal_context();
$demo           = ! empty( $context['demo'] );
$timezone       = (string) ( $args['timezone'] ?? ( $model['teacher_timezone'] ?? '' ) );
$calendar       = (string) ( $args['calendar'] ?? ( $model['teacher_calendar'] ?? '' ) );
$locale         = (string) ( $args['locale'] ?? ( $model['teacher_locale'] ?? '' ) );
$base_url       = (string) ( $args['base_url'] ?? '' );
if ( '' === $base_url ) { $base_url = home_url( '/teacher-portal/?teacher-view=classes' ); }
$start_index    = (int) ( $args['start_index'] ?? 0 );
$requested_month = '';
$requested_day   = '';
foreach ( array( 'tp-month', 'tp-day' ) as $query_key ) {
	if ( ! isset( $_GET[ $query_key ] ) ) { continue; }
	$value = (string) $_GET[ $query_key ];
	if ( function_exists( 'wp_unslash' ) ) { $value = (string) wp_unslash( $value ); }
	if ( function_exists( 'sanitize_text_field' ) ) { $value = (string) sanitize_text_field( $value ); }
	if ( 'tp-day' === $query_key ) { $requested_day = $value; } else { $requested_month = $value; }
}
$requested_month = preg_match( '/^\d{4}-\d{2}$/', $requested_month ) ? $requested_month : '';
$requested_day   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $requested_day ) ? $requested_day : '';
$grid           = dzn_theme_teacher_portal_month_grid( $requested_month, $calendar, $timezone, $locale );
$date_counts    = array();
$day_labels     = array();
foreach ( $grid['cells'] as $cell ) {
	if ( is_array( $cell ) ) { $day_labels[ (string) $cell['date'] ] = (string) $cell['label']; }
}
$month_items    = array();
foreach ( $all_items as $class_item ) {
	if ( ! is_array( $class_item ) ) { continue; }
	$local_date = (string) ( $class_item['local_date'] ?? '' );
	if ( '' === $local_date ) { continue; }
	if ( ! isset( $date_counts[ $local_date ] ) ) { $date_counts[ $local_date ] = array( 'count' => 0, 'intro' => 0 ); }
	$date_counts[ $local_date ]['count']++;
	if ( ! empty( $class_item['is_intro'] ) ) { $date_counts[ $local_date ]['intro']++; }
	if ( $local_date >= $grid['first_date'] && $local_date <= $grid['last_date'] ) { $month_items[] = $class_item; }
}
usort( $month_items, static fn( $a, $b ) => strcmp( (string) ( $a['starts_at_utc'] ?? '' ), (string) ( $b['starts_at_utc'] ?? '' ) ) );
$day_groups = array();
$has_intro_days = false;
foreach ( $month_items as $class_item ) {
	$local_date = (string) $class_item['local_date'];
	$day_groups[ $local_date ][] = $class_item;
	if ( ! empty( $class_item['is_intro'] ) ) { $has_intro_days = true; }
}
$selected_day = $requested_day !== '' && isset( $day_groups[ $requested_day ] ) ? $requested_day : '';
?>
<section id="tp-classes-calendar" class="dzn-tp-section dzn-tp-class-section dzn-tp-calendar-section" aria-labelledby="tp-classes-calendar-title">
	<div class="dzn-tp-heading">
		<div>
			<h3 id="tp-classes-calendar-title" class="dzn-tp-class-section__title">تقویم کلاس‌ها</h3>
			<p class="dzn-tp-help">روزهای دارای کلاس نشان‌دار هستند؛ برای دیدن کلاس‌های یک روز، همان روز را انتخاب کنید.</p>
		</div>
		<?php if ( '' !== $timezone ) : ?><span class="dzn-timezone-label"><span aria-hidden="true">◷</span> <?php echo esc_html( $timezone ); ?></span><?php endif; ?>
	</div>
	<div class="dzn-tp-month">
		<div class="dzn-tp-month__bar">
			<?php if ( '' !== $grid['prev'] ) : ?><a class="dzn-tp-month__nav" href="<?php echo esc_url( add_query_arg( 'tp-month', $grid['prev'], $base_url ) ); ?>" aria-label="ماه پیش"><span aria-hidden="true">›</span></a><?php else : ?><span class="dzn-tp-month__nav is-disabled" aria-hidden="true">›</span><?php endif; ?>
			<strong class="dzn-tp-month__label" aria-live="polite"><?php echo esc_html( (string) $grid['label'] ); ?></strong>
			<a class="dzn-tp-month__nav" href="<?php echo esc_url( add_query_arg( 'tp-month', $grid['next'], $base_url ) ); ?>" aria-label="ماه بعد"><span aria-hidden="true">‹</span></a>
		</div>
		<div class="dzn-tp-month__weekdays" aria-hidden="true">
			<?php foreach ( $grid['weekdays'] as $weekday ) : ?><span><?php echo esc_html( $weekday ); ?></span><?php endforeach; ?>
		</div>
		<div class="dzn-tp-month__grid">
			<?php foreach ( $grid['cells'] as $cell ) : ?>
				<?php if ( null === $cell ) : ?>
					<span class="dzn-tp-month__cell is-empty" aria-hidden="true"></span>
				<?php else : ?>
					<?php $marks = $date_counts[ (string) $cell['date'] ] ?? array( 'count' => 0, 'intro' => 0 ); ?>
					<?php if ( $marks['count'] > 0 ) : ?>
						<a class="dzn-tp-month__cell has-classes<?php echo $cell['today'] ? ' is-today' : ''; ?><?php echo $marks['intro'] > 0 ? ' has-intro' : ''; ?><?php echo $cell['date'] === $selected_day ? ' is-selected' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'tp-month' => (string) $grid['key'], 'tp-day' => (string) $cell['date'] ), $base_url ) . '#tp-calendar-day' ); ?>" data-dzn-tp-day-link="<?php echo esc_attr( (string) $cell['date'] ); ?>"<?php echo $cell['date'] === $selected_day ? ' aria-current="date"' : ''; ?> aria-label="<?php echo esc_attr( (string) $cell['label'] . '، ' . dzn_theme_teacher_portal_digits( (int) $marks['count'] ) . ' کلاس' ); ?>">
							<span class="dzn-tp-month__day"><?php echo esc_html( (string) $cell['day'] ); ?></span>
							<span class="dzn-tp-month__mark" aria-hidden="true"><?php echo esc_html( dzn_theme_teacher_portal_digits( (int) $marks['count'] ) ); ?></span>
						</a>
					<?php else : ?>
						<span class="dzn-tp-month__cell<?php echo $cell['today'] ? ' is-today' : ''; ?>"><span class="dzn-tp-month__day"><?php echo esc_html( (string) $cell['day'] ); ?></span></span>
					<?php endif; ?>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
		<p class="dzn-tp-month__legend dzn-tp-help">
			<span class="dzn-tp-month__legend-dot" aria-hidden="true"></span> روز دارای کلاس
			<?php if ( $has_intro_days ) : ?><span class="dzn-tp-month__legend-dot has-intro" aria-hidden="true"></span> روز دارای کلاس آشنایی<?php endif; ?>
		</p>
	</div>
	<p id="tp-calendar-day" class="dzn-tp-help dzn-tp-calendar-empty" data-dzn-tp-calendar-hint<?php echo '' !== $selected_day ? ' hidden' : ''; ?>>برای دیدن کلاس‌های یک روز، آن روز را در تقویم انتخاب کنید.</p>
<?php if ( $day_groups ) : ?>
	<div class="dzn-tp-calendar-days">
		<?php $row_index = $start_index; ?>
		<?php foreach ( $day_groups as $day_date => $day_items ) : ?>
			<?php
			$day_students = array();
			foreach ( $day_items as $class_item ) {
				$name = trim( (string) ( $class_item['student'] ?? '' ) );
				if ( '' !== $name ) { $day_students[ $name ] = $name; }
			}
			ksort( $day_students, SORT_NATURAL | SORT_FLAG_CASE );
			?>
			<section class="dzn-tp-day" id="tp-day-<?php echo esc_attr( (string) $day_date ); ?>" data-dzn-tp-day="<?php echo esc_attr( (string) $day_date ); ?>" data-dzn-tp-filter-scope<?php echo $day_date === $selected_day ? '' : ' hidden'; ?>>
				<div class="dzn-tp-day__head">
					<h4 class="dzn-tp-day__title"><?php echo esc_html( (string) ( $day_labels[ (string) $day_date ] ?? ( $day_items[0]['date'] ?? '' ) ) ); ?></h4>
					<a class="dzn-tp-day__clear" href="<?php echo esc_url( add_query_arg( 'tp-month', (string) $grid['key'], $base_url ) . '#tp-classes-calendar' ); ?>" data-dzn-tp-day-clear>بستن</a>
				</div>
				<?php if ( $day_students ) : ?>
				<div class="dzn-tp-class-filter" data-dzn-tp-student-filter>
					<label for="tp-calendar-student-filter-<?php echo esc_attr( (string) $day_date ); ?>">هنرجو</label>
					<select id="tp-calendar-student-filter-<?php echo esc_attr( (string) $day_date ); ?>" data-dzn-tp-student-filter-select>
						<option value="">همهٔ هنرجوها</option>
						<?php foreach ( $day_students as $student_name ) : ?><option value="<?php echo esc_attr( $student_name ); ?>"><?php echo esc_html( $student_name ); ?></option><?php endforeach; ?>
					</select>
					<span class="dzn-tp-class-filter__status" data-dzn-tp-student-filter-status role="status" aria-live="polite"></span>
				</div>
				<?php endif; ?>
				<ol class="dzn-tp-class-list dzn-tp-class-list--rows">
					<?php foreach ( $day_items as $class_item ) : ?>
						<?php dzn_theme_teacher_portal_component( 'class-row', array( 'item' => $class_item, 'index' => $row_index++, 'id_prefix' => 'cal', 'timezone' => $timezone, 'model' => $model, 'demo' => $demo ) ); ?>
					<?php endforeach; ?>
				</ol>
				<p class="dzn-tp-help dzn-tp-class-filter__empty" data-dzn-tp-student-filter-empty hidden>برای این هنرجو در این روز کلاسی ثبت نشده است.</p>
			</section>
		<?php endforeach; ?>
	</div>
<?php endif; ?>
</section>
