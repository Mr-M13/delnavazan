<?php
/**
 * Primary LiveKit classroom action with an explicit Google Meet fallback.
 *
 * Every URL, nonce and identifier is supplied by the backend view model. Nothing here opens a
 * provider link directly; both actions POST to an authenticated, nonce-protected controller.
 *
 * @package DelnavazanTheme
 */

$item = isset( $args['item'] ) && is_array( $args['item'] ) ? $args['item'] : array();
$role = isset( $args['role'] ) ? (string) $args['role'] : 'student';
$is_booking = 'booking' === (string) ( $item['livekit_kind'] ?? '' ) || ( ! empty( $item['confirmation_uid'] ) && empty( $item['lesson_uid'] ) );
$livekit_available = ! empty( $item['livekit_available'] );
$meet_available = ! empty( $item['meet_available'] ) || ! empty( $item['join_available'] ) || ( ! array_key_exists( 'livekit_available', $item ) && true === ( $item['start_available'] ?? null ) && ! empty( $item['join_action_url'] ) );
$livekit_url = (string) ( $item['livekit_action_url'] ?? '' );
if ( $livekit_available && '' === $livekit_url && function_exists( 'admin_url' ) ) {
	$livekit_url = admin_url( 'admin-post.php?action=' . ( $role === 'teacher' ? 'dzn_teacher_livekit_class' : 'dzn_student_livekit_class' ) );
}
$livekit_nonce = (string) ( $item['livekit_nonce'] ?? ( function_exists( 'wp_create_nonce' ) ? wp_create_nonce( ( 'teacher' === $role ? 'dzn_teacher' : 'dzn_student' ) . '_livekit_class' ) : '' ) );
$meet_url = (string) ( $item['join_action_url'] ?? ( function_exists( 'admin_url' ) ? admin_url( 'admin-post.php?action=' . ( $role === 'teacher' ? 'dzn_teacher_join_class' : 'dzn_student_join_class' ) ) : '' ) );
$meet_nonce = (string) ( $item['join_nonce'] ?? ( function_exists( 'wp_create_nonce' ) ? wp_create_nonce( $role === 'teacher' ? 'dzn_teacher_join_class' : 'dzn_student_join_class' ) : '' ) );
$message = (string) ( $item['livekit_message'] ?? $item['join_message'] ?? ( $role === 'teacher' ? 'زمان ورود نرسیده یا دسترسی امن برای این کلاس تأیید نشده است.' : 'ورود به کلاس در این لحظه فعال نیست.' ) );
$primary_class = (string) ( $args['primary_class'] ?? 'dzn-button' );
$secondary_class = (string) ( $args['secondary_class'] ?? 'dzn-button dzn-button--secondary' );
$meet_label = (string) ( $args['meet_label'] ?? 'ورود با گوگل میت' );
$show_backup_note = ! array_key_exists( 'show_backup_note', $args ) || ! empty( $args['show_backup_note'] );
$fields = static function () use ( $item, $is_booking ): void {
	if ( $is_booking ) {
		?><input type="hidden" name="kind" value="booking"><input type="hidden" name="confirmation_uid" value="<?php echo esc_attr( (string) ( $item['confirmation_uid'] ?? '' ) ); ?>"><?php
		return;
	}
	?><input type="hidden" name="kind" value="lesson"><input type="hidden" name="lesson_uid" value="<?php echo esc_attr( (string) ( $item['lesson_uid'] ?? '' ) ); ?>"><input type="hidden" name="schedule_version_uid" value="<?php echo esc_attr( (string) ( $item['schedule_version_uid'] ?? '' ) ); ?>"><?php
};
?>
<div class="dzn-classroom-actions<?php echo $meet_available ? ' has-backup' : ''; ?>">
	<?php if ( $livekit_available ) : ?>
		<form method="post" target="_blank" rel="noopener noreferrer" action="<?php echo esc_url( $livekit_url ); ?>" class="dzn-classroom-actions__form"<?php echo 'teacher' === $role ? ' data-dzn-tp-submit data-dzn-tp-reenable="5000"' : ''; ?>>
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $livekit_nonce ); ?>">
			<?php $fields(); ?>
			<button class="<?php echo esc_attr( $primary_class ); ?>" type="submit"<?php echo 'teacher' === $role ? ' data-dzn-tp-busy-label="در حال باز کردن کلاس…"' : ''; ?>>ورود به کلاس</button>
		</form>
	<?php endif; ?>
	<?php if ( $meet_available ) : ?>
		<form method="post" target="_blank" rel="noopener noreferrer" action="<?php echo esc_url( $meet_url ); ?>" class="dzn-classroom-actions__form dzn-classroom-actions__backup"<?php echo 'teacher' === $role ? ' data-dzn-tp-submit data-dzn-tp-reenable="5000"' : ''; ?>>
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $meet_nonce ); ?>">
			<?php $fields(); ?>
			<button class="<?php echo esc_attr( $secondary_class ); ?>" type="submit"<?php echo 'teacher' === $role ? ' data-dzn-tp-busy-label="در حال باز کردن لینک…"' : ''; ?>><?php echo esc_html( $meet_label ); ?></button>
		</form>
		<?php if ( $show_backup_note ) : ?><p class="dzn-classroom-actions__note"><?php echo esc_html( $livekit_available ? 'گوگل میت گزینهٔ پشتیبان است.' : 'کلاس زندهٔ دلنوازان در دسترس نیست؛ گزینهٔ پشتیبان فعال است.' ); ?></p><?php endif; ?>
	<?php endif; ?>
	<?php if ( ! $livekit_available && ! $meet_available && '' !== $message ) : ?>
		<p class="dzn-portal-help dzn-classroom-actions__unavailable"><?php echo esc_html( $message ); ?></p>
	<?php endif; ?>
</div>
