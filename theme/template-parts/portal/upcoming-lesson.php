<?php
/**
 * Dominant Upcoming Lesson presentation.
 *
 * @package DelnavazanTheme
 */

$lesson = isset( $args['lesson'] ) && is_array( $args['lesson'] ) ? $args['lesson'] : array();
$state = isset( $lesson['presentation_state'] ) ? (string) $lesson['presentation_state'] : 'none';
$approved_states = array( 'upcoming', 'starting_soon', 'in_progress', 'ended', 'unknown', 'time_changed', 'academy_cancelled', 'awaiting_reschedule', 'none' );
$state_is_valid = in_array( $state, $approved_states, true );
$has_lesson = $state_is_valid && 'none' !== $state && ! empty( $lesson['course'] );
?>
<section class="dzn-portal-section dzn-upcoming" aria-labelledby="dzn-upcoming-title">
	<?php $absence_status = sanitize_key( (string) ( $_GET['absence_status'] ?? '' ) ); $join_status = sanitize_key( (string) ( $_GET['join_status'] ?? '' ) ); $calendar_status = sanitize_key( (string) ( $_GET['calendar_status'] ?? '' ) ); if ( 'absence_recorded' === $absence_status ) : ?><p class="dzn-portal-action-status" aria-live="polite"><?php esc_html_e( 'اطلاع غیبت شما ثبت شد.', 'delnavazan-theme' ); ?></p><?php elseif ( 'absence_unavailable' === $absence_status ) : ?><p class="dzn-portal-action-status" aria-live="polite"><?php esc_html_e( 'ثبت غیبت برای این جلسه در حال حاضر امکان‌پذیر نیست.', 'delnavazan-theme' ); ?></p><?php elseif ( 'join_unavailable' === $join_status ) : ?><p class="dzn-portal-action-status" aria-live="polite"><?php esc_html_e( 'پیوند امن Meet در دسترس نیست یا زمان ورود به کلاس نرسیده است. لطفاً دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.', 'delnavazan-theme' ); ?></p><?php elseif ( 'export_unavailable' === $calendar_status ) : ?><p class="dzn-portal-action-status" aria-live="polite"><?php esc_html_e( 'دریافت فایل تقویم برای این کلاس ممکن نشد. لطفاً صفحه را تازه کنید و دوباره تلاش کنید.', 'delnavazan-theme' ); ?></p><?php endif; ?>
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
				<div><dt><?php esc_html_e( 'روز', 'delnavazan-theme' ); ?></dt><dd><time datetime="<?php echo esc_attr( (string) ( $lesson['starts_at_iso'] ?? '' ) ); ?>"><?php echo esc_html( $lesson['date'] ?? '' ); ?></time></dd></div>
				<div><dt><?php esc_html_e( 'ساعت', 'delnavazan-theme' ); ?></dt><dd><time datetime="<?php echo esc_attr( (string) ( $lesson['starts_at_iso'] ?? '' ) ); ?>"><bdi><?php echo esc_html( $lesson['time'] ?? '' ); ?></bdi></time></dd></div>
			</dl>
		</div>

		<div class="dzn-upcoming__actions">
			<?php if ( ! empty( $lesson['join_available'] ) && ! empty( $lesson['join_action_url'] ) ) : ?>
				<form method="post" target="_blank" action="<?php echo esc_url( (string) $lesson['join_action_url'] ); ?>">
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( (string) ( $lesson['join_nonce'] ?? '' ) ); ?>">
					<?php if ( ! empty( $lesson['confirmation_uid'] ) ) : ?>
						<input type="hidden" name="confirmation_uid" value="<?php echo esc_attr( (string) $lesson['confirmation_uid'] ); ?>">
					<?php else : ?>
						<input type="hidden" name="lesson_uid" value="<?php echo esc_attr( (string) ( $lesson['lesson_uid'] ?? '' ) ); ?>">
						<input type="hidden" name="schedule_version_uid" value="<?php echo esc_attr( (string) ( $lesson['schedule_version_uid'] ?? '' ) ); ?>">
					<?php endif; ?>
					<button class="dzn-button dzn-upcoming__join" type="submit"><?php esc_html_e( 'ورود به کلاس', 'delnavazan-theme' ); ?></button>
				</form>
			<?php elseif ( ! empty( $lesson['join_url'] ) ) : ?>
				<a class="dzn-button dzn-upcoming__join" href="<?php echo esc_url( $lesson['join_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'ورود به کلاس', 'delnavazan-theme' ); ?></a>
			<?php endif; ?>
			<?php if ( ! empty( $lesson['absence_available'] ) ) : ?>
				<button class="dzn-button dzn-button--secondary" type="button" data-dzn-dialog-open="dzn-absence-dialog"><?php esc_html_e( 'اطلاع غیبت', 'delnavazan-theme' ); ?></button>
			<?php endif; ?>
			<?php if ( ! empty( $lesson['calendar_export_url'] ) ) : ?><a class="dzn-portal-text-action" href="<?php echo esc_url( (string) $lesson['calendar_export_url'] ); ?>"><?php esc_html_e( 'افزودن به تقویم', 'delnavazan-theme' ); ?></a><?php endif; ?>
		</div>
		<?php if ( empty( $lesson['join_url'] ) && empty( $lesson['join_available'] ) && ! empty( $lesson['join_message'] ) ) : ?><p id="dzn-join-pending" class="dzn-portal-help"><?php echo esc_html( (string) $lesson['join_message'] ); ?></p><?php endif; ?>
		<?php if ( empty( $lesson['absence_available'] ) && ! empty( $lesson['absence_message'] ) ) : ?><p class="dzn-portal-help"><?php echo esc_html( (string) $lesson['absence_message'] ); ?></p><?php endif; ?>
	<?php endif; ?>

	<?php if ( $has_lesson && ! empty( $lesson['absence_available'] ) ) : ?>
	<dialog id="dzn-absence-dialog" class="dzn-portal-dialog" aria-labelledby="dzn-absence-title">
		<div class="dzn-portal-dialog__body">
			<button class="dzn-portal-icon-button dzn-portal-dialog__close" type="button" data-dzn-dialog-close aria-label="<?php esc_attr_e( 'بستن', 'delnavazan-theme' ); ?>">×</button>
			<h2 id="dzn-absence-title"><?php esc_html_e( 'اطلاع غیبت', 'delnavazan-theme' ); ?></h2>
			<p><?php esc_html_e( 'با ثبت این اطلاع، غیبت شما به‌عنوان ادعای دانش‌آموز برای همین جلسه ثبت می‌شود. این اقدام به‌تنهایی وضعیت نهایی حضور یا اعتبار جلسه را تعیین نمی‌کند.', 'delnavazan-theme' ); ?></p>
			<form method="post" action="<?php echo esc_url( (string) ( $lesson['absence_action_url'] ?? '' ) ); ?>">
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( (string) ( $lesson['absence_nonce'] ?? '' ) ); ?>">
				<input type="hidden" name="lesson_uid" value="<?php echo esc_attr( (string) ( $lesson['lesson_uid'] ?? '' ) ); ?>">
				<input type="hidden" name="schedule_version_uid" value="<?php echo esc_attr( (string) ( $lesson['schedule_version_uid'] ?? '' ) ); ?>">
				<button class="dzn-button" type="submit"><?php esc_html_e( 'ثبت اطلاع غیبت', 'delnavazan-theme' ); ?></button>
			</form>
		</div>
	</dialog>
	<?php endif; ?>
</section>
