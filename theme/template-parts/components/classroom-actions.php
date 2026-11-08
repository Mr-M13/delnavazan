<?php
/**
 * Google Meet classroom action.
 *
 * A Student goes straight to the authenticated join controller: one click, one navigation, and the
 * server still re-proves ownership and the join window before the vaulted Meet URL is opened. A
 * Teacher keeps Delnavazan's branded page because it carries the pre-class briefing and becomes the
 * post-class finish page.
 *
 * The actual Meet URL is never present in this markup.
 *
 * @package DelnavazanTheme
 */

$item = isset( $args['item'] ) && is_array( $args['item'] ) ? $args['item'] : array();
$role = isset( $args['role'] ) ? (string) $args['role'] : 'student';
$is_booking = ! empty( $item['confirmation_uid'] ) && empty( $item['lesson_uid'] );
$join_available = ! empty( $item['meet_available'] ) || ! empty( $item['join_available'] ) || true === ( $item['start_available'] ?? null );
$message = (string) ( $item['join_message'] ?? ( $role === 'teacher' ? 'زمان ورود نرسیده یا دسترسی امن برای این کلاس تأیید نشده است.' : 'ورود به کلاس در این لحظه فعال نیست.' ) );
$primary_class = (string) ( $args['primary_class'] ?? 'dzn-button' );
$has_identifiers = $is_booking ? (string) ( $item['confirmation_uid'] ?? '' ) !== '' : ( (string) ( $item['lesson_uid'] ?? '' ) !== '' && (string) ( $item['schedule_version_uid'] ?? '' ) !== '' );

$join_action_url = (string) ( $item['join_action_url'] ?? '' );
$join_nonce = (string) ( $item['join_nonce'] ?? '' );
$join_action = $is_booking
	? ( 'teacher' === $role ? 'dzn_teacher_join_booking_class' : 'dzn_student_join_booking_class' )
	: ( 'teacher' === $role ? 'dzn_teacher_join_class' : 'dzn_student_join_class' );
if ( '' === $join_action_url ) { $join_action_url = admin_url( 'admin-post.php?action=' . $join_action ); }
if ( '' === $join_nonce ) { $join_nonce = wp_create_nonce( $join_action ); }

$entry_args = $is_booking
	? array( 'confirmation_uid' => (string) ( $item['confirmation_uid'] ?? '' ) )
	: array(
		'lesson_uid' => (string) ( $item['lesson_uid'] ?? '' ),
		'schedule_version_uid' => (string) ( $item['schedule_version_uid'] ?? '' ),
	);
$entry_url = add_query_arg( $entry_args, home_url( '/music-classroom/' ) );
?>
<div class="dzn-classroom-actions">
	<?php if ( $join_available && $has_identifiers ) : ?>
		<?php if ( 'student' === $role ) : ?>
			<form class="dzn-classroom-actions__form" method="post" action="<?php echo esc_url( $join_action_url ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( $join_action ); ?>">
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $join_nonce ); ?>">
				<?php if ( $is_booking ) : ?>
					<input type="hidden" name="confirmation_uid" value="<?php echo esc_attr( (string) ( $item['confirmation_uid'] ?? '' ) ); ?>">
				<?php else : ?>
					<input type="hidden" name="lesson_uid" value="<?php echo esc_attr( (string) ( $item['lesson_uid'] ?? '' ) ); ?>">
					<input type="hidden" name="schedule_version_uid" value="<?php echo esc_attr( (string) ( $item['schedule_version_uid'] ?? '' ) ); ?>">
				<?php endif; ?>
				<button class="<?php echo esc_attr( $primary_class ); ?>" type="submit">ورود به کلاس Google Meet</button>
			</form>
			<p class="dzn-classroom-actions__note">با یک کلیک وارد کلاس می‌شوید. پیوند امن Meet فقط پس از تأیید سرور باز می‌شود.</p>
		<?php else : ?>
			<a class="<?php echo esc_attr( $primary_class ); ?>" href="<?php echo esc_url( $entry_url ); ?>" target="_blank" rel="noopener noreferrer">ورود به کلاس Google Meet</a>
			<p class="dzn-classroom-actions__note">صفحهٔ آماده‌سازی و پایان کلاس دلنوازان باز می‌شود؛ پیوند امن Meet پس از تأیید سرور در اختیار شما قرار می‌گیرد.</p>
		<?php endif; ?>
	<?php elseif ( '' !== $message ) : ?>
		<p class="dzn-portal-help dzn-classroom-actions__unavailable"><?php echo esc_html( $message ); ?></p>
	<?php endif; ?>
</div>
