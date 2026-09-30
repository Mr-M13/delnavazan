<?php
/**
 * Human-readable Upcoming Lesson state notice.
 *
 * The supplied presentation state is separate from lifecycle, schedule,
 * attendance and entitlement evidence and is never inferred here.
 *
 * @package DelnavazanTheme
 */

$state = isset( $args['state'] ) ? (string) $args['state'] : 'none';
$message = isset( $args['message'] ) ? (string) $args['message'] : '';
$owed = isset( $args['owed'] ) ? (string) $args['owed'] : '';
$states = array(
	'upcoming'           => array( 'label' => 'کلاس پیشِ رو', 'tone' => 'information' ),
	'starting_soon'      => array( 'label' => 'به‌زودی آغاز می‌شود', 'tone' => 'accent' ),
	'absence_notified'   => array( 'label' => 'غیبت شما اطلاع داده شده', 'tone' => 'muted' ),
	'time_changed'       => array( 'label' => 'زمان کلاس تغییر کرده', 'tone' => 'warning' ),
	'academy_cancelled'  => array( 'label' => 'کلاس از سوی مدرس یا آموزشگاه لغو شده', 'tone' => 'error' ),
	'awaiting_reschedule'=> array( 'label' => 'در انتظار زمان جدید', 'tone' => 'warning' ),
	'none'               => array( 'label' => 'کلاس بعدی هنوز تعیین نشده', 'tone' => 'muted' ),
);
$presentation = $states[ $state ] ?? array(
	'label' => 'اطلاعات کلاس در دسترس نیست؛ لطفاً دوباره بررسی کنید.',
	'tone'  => 'error',
);
?>
<div class="dzn-lesson-state dzn-lesson-state--<?php echo esc_attr( $presentation['tone'] ); ?>">
	<p class="dzn-lesson-state__label"><?php echo esc_html( $presentation['label'] ); ?></p>
	<?php if ( $message ) : ?><p><?php echo esc_html( $message ); ?></p><?php endif; ?>
	<?php if ( $owed ) : ?><p class="dzn-lesson-state__entitlement"><strong><?php esc_html_e( 'حق جلسه:', 'delnavazan-theme' ); ?></strong> <?php echo esc_html( $owed ); ?></p><?php endif; ?>
</div>
