<?php
/**
 * The Teacher Classes screen: intro classes, today, the next three days, the month calendar and
 * completed history.
 *
 * Presentation only: every action below uses a backend-issued URL, nonce and identifier from the
 * view model. Intro classes live in their own section regardless of the date and are never
 * repeated in Today or the next days. Farther classes stay in the calendar projection only. The
 * month calendar marks every day with a class and keeps the same row actions in its day list.
 */
$model    = is_array( $args['model'] ?? null ) ? $args['model'] : array();
$context  = dzn_theme_teacher_portal_context();
$demo     = ! empty( $context['demo'] );
$settings = (string) ( $context['settings_url'] ?? '' );
$timezone = (string) ( $args['timezone'] ?? ( $model['teacher_timezone'] ?? $model['profile']['timezone'] ?? '' ) );
$intro_items    = isset( $args['intro_items'] ) && is_array( $args['intro_items'] ) ? $args['intro_items'] : ( isset( $model['intro_classes'] ) && is_array( $model['intro_classes'] ) ? $model['intro_classes'] : array() );
$today_items    = isset( $args['today_items'] ) && is_array( $args['today_items'] ) ? $args['today_items'] : ( isset( $model['today_classes'] ) && is_array( $model['today_classes'] ) ? $model['today_classes'] : array() );
$next_items     = isset( $args['next_items'] ) && is_array( $args['next_items'] ) ? $args['next_items'] : ( isset( $model['next_classes'] ) && is_array( $model['next_classes'] ) ? $model['next_classes'] : array() );
$past_items     = isset( $args['past_items'] ) && is_array( $args['past_items'] ) ? $args['past_items'] : ( isset( $model['past_classes'] ) && is_array( $model['past_classes'] ) ? $model['past_classes'] : array() );
$all_items      = isset( $args['items'] ) && is_array( $args['items'] ) && $args['items'] ? $args['items'] : ( isset( $model['classes'] ) && is_array( $model['classes'] ) ? $model['classes'] : array() );
$classes_url    = '';
foreach ( (array) ( $model['navigation'] ?? array() ) as $nav_item ) {
	if ( is_array( $nav_item ) && 'classes' === ( $nav_item['screen'] ?? null ) && is_string( $nav_item['url'] ?? null ) ) { $classes_url = (string) $nav_item['url']; }
}
if ( '' === $classes_url ) { $classes_url = home_url( '/teacher-portal/?teacher-view=classes' ); }
$row_index = 0;
// The Today screen renders the current local day only; the full structure below is Classes.
$show_more = ! array_key_exists( 'show_upcoming', $args ) || ! empty( $args['show_upcoming'] );
?>
<section class="dzn-tp-section dzn-tp-classes" aria-labelledby="tp-classes">
	<h2 id="tp-classes" class="dzn-tp-sr">کلاس‌ها</h2>
	<?php if ( isset( $_GET['intro_notice'] ) ) : ?><p class="dzn-tp-action-status" role="<?php echo sanitize_key( (string) ( $_GET['intro_status'] ?? '' ) ) === 'failed' ? 'alert' : 'status'; ?>"><?php echo esc_html( sanitize_text_field( wp_unslash( (string) $_GET['intro_notice'] ) ) ); ?></p><?php endif; ?>
	<?php dzn_theme_teacher_portal_component( 'test-classes', array( 'items' => (array) ( $model['god_mode_test_classes'] ?? array() ), 'timezone' => $timezone, 'calendar' => (string) ( $model['teacher_calendar'] ?? '' ), 'locale' => (string) ( $model['teacher_locale'] ?? '' ) ) ); ?>
<?php if ( $show_more && $intro_items ) : ?>
	<section class="dzn-tp-class-section dzn-tp-class-section--intro" aria-labelledby="tp-classes-intro">
		<div class="dzn-tp-class-section__heading">
			<h3 id="tp-classes-intro" class="dzn-tp-class-section__title">کلاس‌های آشنایی</h3>
			<span class="dzn-tp-class-section__count"><?php echo esc_html( dzn_theme_teacher_portal_digits( count( $intro_items ) ) ); ?></span>
		</div>
		<p class="dzn-tp-help dzn-tp-class-section__help">همهٔ کلاس‌های آشنایی شما، مستقل از تاریخشان.</p>
		<ol class="dzn-tp-class-list dzn-tp-class-list--rows dzn-tp-class-list--intro">
			<?php foreach ( $intro_items as $item ) : ?>
				<?php dzn_theme_teacher_portal_component( 'class-row', array( 'item' => $item, 'index' => $row_index++, 'id_prefix' => 'tp', 'timezone' => $timezone, 'model' => $model, 'demo' => $demo ) ); ?>
			<?php endforeach; ?>
		</ol>
	</section>
<?php endif; ?>
	<section class="dzn-tp-class-section dzn-tp-class-section--today" aria-labelledby="tp-classes-today">
		<div class="dzn-tp-class-section__heading">
			<h3 id="tp-classes-today" class="dzn-tp-class-section__title">کلاس‌های امروز</h3>
			<?php if ( ! $show_more && '' !== $timezone ) : ?><span class="dzn-timezone-label"><span aria-hidden="true">◷</span> <?php echo esc_html( $timezone ); ?></span><?php endif; ?>
		</div>
		<p class="dzn-tp-help dzn-tp-class-section__help">برای جزئیات، کلاس را باز کنید.</p>
	<?php if ( ! $today_items ) : ?>
		<div class="dzn-tp-empty-state dzn-tp-empty-state--compact" role="status"><h3>کلاسی برای امروز ثبت نشده است</h3><?php if ( '' !== $settings ) : ?><div class="dzn-tp-empty-state__actions"><a class="dzn-button dzn-button--secondary" href="<?php echo esc_url( $settings ); ?>">بررسی زمان‌های در دسترس</a></div><?php endif; ?></div>
	<?php else : ?>
		<ol class="dzn-tp-class-list">
			<?php foreach ( $today_items as $item ) : ?>
				<?php dzn_theme_teacher_portal_component( 'class-row', array( 'item' => $item, 'index' => $row_index++, 'id_prefix' => 'tp', 'timezone' => $timezone, 'model' => $model, 'demo' => $demo ) ); ?>
			<?php endforeach; ?>
		</ol>
	<?php endif; ?>
	</section>
	<?php if ( $show_more ) : ?>
	<section class="dzn-tp-class-section dzn-tp-class-section--next" aria-labelledby="tp-classes-next">
		<h3 id="tp-classes-next" class="dzn-tp-class-section__title">۳ روز آینده</h3>
	<?php if ( ! $next_items ) : ?>
		<p class="dzn-tp-help dzn-tp-class-section__help">در سه روز آینده کلاسی ثبت نشده است.</p>
	<?php else : ?>
		<ol class="dzn-tp-class-list dzn-tp-class-list--rows">
			<?php foreach ( $next_items as $item ) : ?>
				<?php dzn_theme_teacher_portal_component( 'class-row', array( 'item' => $item, 'index' => $row_index++, 'id_prefix' => 'tp', 'timezone' => $timezone, 'model' => $model, 'demo' => $demo ) ); ?>
			<?php endforeach; ?>
		</ol>
	<?php endif; ?>
	</section>
	<?php endif; ?>
	<?php if ( $show_more ) : ?>
	<?php dzn_theme_teacher_portal_component( 'classes-calendar', array( 'all_items' => $all_items, 'timezone' => $timezone, 'calendar' => (string) ( $model['teacher_calendar'] ?? '' ), 'locale' => (string) ( $model['teacher_locale'] ?? '' ), 'base_url' => $classes_url, 'start_index' => $row_index, 'model' => $model, 'demo' => $demo ) ); ?>
	<?php endif; ?>
	<?php if ( $past_items ) : ?>
	<details class="dzn-tp-class-history">
		<summary>کلاس‌های گذشته <span class="dzn-tp-class-history__count"><?php echo esc_html( dzn_theme_teacher_portal_digits( count( $past_items ) ) ); ?></span></summary>
		<ol class="dzn-tp-class-history__list">
			<?php foreach ( $past_items as $past ) : ?>
				<?php
				$past_valid = is_array( $past )
					&& '' !== trim( (string) ( $past['ref'] ?? '' ) )
					&& '' !== trim( (string) ( $past['date'] ?? '' ) )
					&& '' !== trim( (string) ( $past['time'] ?? '' ) )
					&& '' !== trim( (string) ( $past['student'] ?? '' ) )
					&& '' !== trim( (string) ( $past['outcome'] ?? '' ) );
				if ( ! $past_valid ) { continue; }
				?>
				<li class="dzn-tp-class-history__item is-<?php echo esc_attr( (string) ( $past['tone'] ?? 'muted' ) ); ?>">
					<p class="dzn-tp-class-history__when"><time><?php echo esc_html( (string) $past['date'] ); ?> · <?php echo esc_html( (string) $past['time'] ); ?></time></p>
					<p class="dzn-tp-class-history__who"><strong><?php echo esc_html( (string) $past['student'] ); ?></strong><?php if ( '' !== trim( (string) ( $past['course'] ?? '' ) ) ) : ?> · <?php echo esc_html( (string) $past['course'] ); ?><?php endif; ?><?php if ( '' !== trim( (string) ( $past['term_label'] ?? '' ) ) ) : ?> · <?php echo esc_html( (string) $past['term_label'] ); ?><?php endif; ?></p>
					<p class="dzn-tp-class-history__outcome"><?php echo esc_html( (string) $past['outcome'] ); ?></p>
					<?php if ( '' !== trim( (string) ( $past['term_progress_label'] ?? '' ) ) ) : ?><p class="dzn-tp-help"><?php echo esc_html( (string) $past['term_progress_label'] ); ?></p><?php endif; ?>
					<?php if ( function_exists( 'dzn_theme_recording_playback_button' ) ) { echo dzn_theme_recording_playback_button( $past, 'teacher' ); } ?>
				</li>
			<?php endforeach; ?>
		</ol>
	</details>
	<?php endif; ?>
</section>
