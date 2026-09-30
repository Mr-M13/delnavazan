<?php
/**
 * Dominant Upcoming Lesson presentation.
 *
 * @package DelnavazanTheme
 */

$lesson = isset( $args['lesson'] ) && is_array( $args['lesson'] ) ? $args['lesson'] : array();
$state = isset( $lesson['presentation_state'] ) ? (string) $lesson['presentation_state'] : 'none';
$approved_states = array( 'upcoming', 'starting_soon', 'absence_notified', 'time_changed', 'academy_cancelled', 'awaiting_reschedule', 'none' );
$state_is_valid = in_array( $state, $approved_states, true );
$has_lesson = $state_is_valid && 'none' !== $state && ! empty( $lesson['course'] );
?>
<section class="dzn-portal-section dzn-upcoming" aria-labelledby="dzn-upcoming-title">
	<div class="dzn-upcoming__heading">
		<div>
			<p class="dzn-portal-kicker"><?php esc_html_e( 'مهم‌ترین قرار شما', 'delnavazan-theme' ); ?></p>
			<h2 id="dzn-upcoming-title"><?php esc_html_e( 'کلاس بعدی', 'delnavazan-theme' ); ?></h2>
		</div>
		<?php if ( ! empty( $lesson['timezone_label'] ) ) : ?>
			<p class="dzn-timezone-label"><span aria-hidden="true">◷</span> <?php echo esc_html( $lesson['timezone_label'] ); ?></p>
		<?php endif; ?>
	</div>

	<?php
	dzn_theme_portal_component(
		'lesson-state',
		array(
			'state'   => $state,
			'message' => $state_is_valid ? ( $lesson['notice'] ?? '' ) : '',
			'owed'    => $state_is_valid ? ( $lesson['owed_session_label'] ?? '' ) : '',
		)
	);
	?>

	<?php if ( $has_lesson ) : ?>
		<div class="dzn-upcoming__facts">
			<div class="dzn-upcoming__identity">
				<h3><?php echo esc_html( $lesson['course'] ?? '' ); ?></h3>
				<?php if ( ! empty( $lesson['teacher'] ) ) : ?><p><?php echo esc_html( sprintf( 'با %s', $lesson['teacher'] ) ); ?></p><?php endif; ?>
			</div>
			<dl class="dzn-fact-list">
				<div><dt><?php esc_html_e( 'روز', 'delnavazan-theme' ); ?></dt><dd><?php echo esc_html( $lesson['date'] ?? '' ); ?></dd></div>
				<div><dt><?php esc_html_e( 'ساعت', 'delnavazan-theme' ); ?></dt><dd><bdi><?php echo esc_html( $lesson['time'] ?? '' ); ?></bdi></dd></div>
			</dl>
		</div>

		<div class="dzn-upcoming__actions">
			<?php if ( ! empty( $lesson['join_url'] ) ) : ?>
				<a class="dzn-button dzn-upcoming__join" href="<?php echo esc_url( $lesson['join_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'ورود به کلاس', 'delnavazan-theme' ); ?></a>
			<?php else : ?>
				<button class="dzn-button dzn-upcoming__join" type="button" disabled aria-describedby="dzn-join-pending"><?php esc_html_e( 'ورود به کلاس', 'delnavazan-theme' ); ?></button>
			<?php endif; ?>
			<button class="dzn-button dzn-button--secondary" type="button" data-dzn-dialog-open="dzn-absence-dialog"><?php esc_html_e( 'اطلاع غیبت', 'delnavazan-theme' ); ?></button>
			<button class="dzn-portal-text-action" type="button" data-dzn-dialog-open="dzn-calendar-dialog"><?php esc_html_e( 'افزودن به تقویم', 'delnavazan-theme' ); ?></button>
		</div>
		<?php if ( empty( $lesson['join_url'] ) ) : ?><p id="dzn-join-pending" class="dzn-portal-help"><?php esc_html_e( 'پیوند معتبر کلاس هنوز از منبع امن دریافت نشده است.', 'delnavazan-theme' ); ?></p><?php endif; ?>
	<?php endif; ?>

	<?php if ( $has_lesson ) : ?>
	<dialog id="dzn-absence-dialog" class="dzn-portal-dialog" aria-labelledby="dzn-absence-title">
		<div class="dzn-portal-dialog__body">
			<button class="dzn-portal-icon-button dzn-portal-dialog__close" type="button" data-dzn-dialog-close aria-label="<?php esc_attr_e( 'بستن', 'delnavazan-theme' ); ?>">×</button>
			<h2 id="dzn-absence-title"><?php esc_html_e( 'اطلاع غیبت', 'delnavazan-theme' ); ?></h2>
			<p><?php esc_html_e( 'این مرحله فقط شکل آیندهٔ درخواست را نشان می‌دهد و چیزی ثبت نمی‌کند.', 'delnavazan-theme' ); ?></p>
			<div class="dzn-portal-control" role="group" aria-labelledby="dzn-absence-reason-label">
				<label id="dzn-absence-reason-label" for="dzn-absence-reason"><?php esc_html_e( 'توضیح کوتاه (اختیاری)', 'delnavazan-theme' ); ?></label>
				<textarea id="dzn-absence-reason" rows="3"></textarea>
				<button class="dzn-button" type="button" data-dzn-presentation-action aria-describedby="dzn-absence-status"><?php esc_html_e( 'ثبت در آینده', 'delnavazan-theme' ); ?></button>
				<p id="dzn-absence-status" class="dzn-portal-action-status" aria-live="polite"></p>
			</div>
		</div>
	</dialog>

	<dialog id="dzn-calendar-dialog" class="dzn-portal-dialog" aria-labelledby="dzn-calendar-title">
		<div class="dzn-portal-dialog__body">
			<button class="dzn-portal-icon-button dzn-portal-dialog__close" type="button" data-dzn-dialog-close aria-label="<?php esc_attr_e( 'بستن', 'delnavazan-theme' ); ?>">×</button>
			<h2 id="dzn-calendar-title"><?php esc_html_e( 'افزودن به تقویم', 'delnavazan-theme' ); ?></h2>
			<p><?php esc_html_e( 'اتصال امن Google Calendar و دریافت پروندهٔ Apple Calendar در مرحلهٔ آینده فراهم می‌شود.', 'delnavazan-theme' ); ?></p>
			<div class="dzn-portal-dialog__actions" aria-label="<?php esc_attr_e( 'گزینه‌های تقویم در آینده', 'delnavazan-theme' ); ?>">
				<button class="dzn-button" type="button" disabled><?php esc_html_e( 'Google Calendar', 'delnavazan-theme' ); ?></button>
				<button class="dzn-button dzn-button--secondary" type="button" disabled><?php esc_html_e( 'Apple Calendar', 'delnavazan-theme' ); ?></button>
			</div>
		</div>
	</dialog>
	<?php endif; ?>
</section>
