<?php
/**
 * Accessible notice.
 *
 * Arguments: type (info|success|warning|error), title, message.
 *
 * @package DelnavazanTheme
 */

$type    = isset( $args['type'] ) && in_array( $args['type'], array( 'info', 'success', 'warning', 'error' ), true ) ? $args['type'] : 'info';
$title   = isset( $args['title'] ) ? (string) $args['title'] : '';
$message = isset( $args['message'] ) ? (string) $args['message'] : '';
$role    = 'error' === $type ? 'alert' : 'status';
?>
<div class="dzn-notice dzn-notice--<?php echo esc_attr( $type ); ?>" role="<?php echo esc_attr( $role ); ?>">
	<?php if ( $title ) : ?><p class="dzn-notice__title"><?php echo esc_html( $title ); ?></p><?php endif; ?>
	<?php if ( $message ) : ?><p><?php echo esc_html( $message ); ?></p><?php endif; ?>
</div>
