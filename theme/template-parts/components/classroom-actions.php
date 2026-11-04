<?php
/**
 * Google Meet classroom action.
 *
 * The link opens Delnavazan's branded lesson entry page. The actual Meet URL is never present in
 * this markup: the entry page posts to the authenticated join controller, which re-proves ownership
 * and the join window before opening the vaulted link.
 *
 * @package DelnavazanTheme
 */

$item = isset( $args['item'] ) && is_array( $args['item'] ) ? $args['item'] : array();
$role = isset( $args['role'] ) ? (string) $args['role'] : 'student';
$is_booking = ! empty( $item['confirmation_uid'] ) && empty( $item['lesson_uid'] );
$join_available = ! empty( $item['meet_available'] ) || ! empty( $item['join_available'] ) || true === ( $item['start_available'] ?? null );
$message = (string) ( $item['join_message'] ?? ( $role === 'teacher' ? 'زمان ورود نرسیده یا دسترسی امن برای این کلاس تأیید نشده است.' : 'ورود به کلاس در این لحظه فعال نیست.' ) );
$primary_class = (string) ( $args['primary_class'] ?? 'dzn-button' );

$entry_args = $is_booking
	? array( 'confirmation_uid' => (string) ( $item['confirmation_uid'] ?? '' ) )
	: array(
		'lesson_uid' => (string) ( $item['lesson_uid'] ?? '' ),
		'schedule_version_uid' => (string) ( $item['schedule_version_uid'] ?? '' ),
	);
$entry_url = add_query_arg( $entry_args, home_url( '/music-classroom/' ) );
?>
<div class="dzn-classroom-actions">
	<?php if ( $join_available && ( $is_booking ? $entry_args['confirmation_uid'] !== '' : ( $entry_args['lesson_uid'] !== '' && $entry_args['schedule_version_uid'] !== '' ) ) ) : ?>
		<a class="<?php echo esc_attr( $primary_class ); ?>" href="<?php echo esc_url( $entry_url ); ?>" target="_blank" rel="noopener noreferrer">ورود به کلاس Google Meet</a>
		<p class="dzn-classroom-actions__note">صفحهٔ ورود دلنوازان باز می‌شود و پیوند امن Meet پس از تأیید سرور در اختیار شما قرار می‌گیرد.</p>
	<?php elseif ( '' !== $message ) : ?>
		<p class="dzn-portal-help dzn-classroom-actions__unavailable"><?php echo esc_html( $message ); ?></p>
	<?php endif; ?>
</div>
