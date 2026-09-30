<?php
/**
 * Loading, error and empty-state presentation.
 *
 * Arguments: state (loading|error|empty), title, message.
 *
 * @package DelnavazanTheme
 */

$state   = isset( $args['state'] ) && in_array( $args['state'], array( 'loading', 'error', 'empty' ), true ) ? $args['state'] : 'empty';
$title   = isset( $args['title'] ) ? (string) $args['title'] : '';
$message = isset( $args['message'] ) ? (string) $args['message'] : '';
$live    = 'error' === $state ? 'assertive' : 'polite';
?>
<section class="dzn-state dzn-state--<?php echo esc_attr( $state ); ?>" aria-live="<?php echo esc_attr( $live ); ?>" aria-busy="<?php echo 'loading' === $state ? 'true' : 'false'; ?>">
	<?php if ( 'loading' === $state ) : ?><span class="dzn-spinner" aria-hidden="true"></span><?php endif; ?>
	<?php if ( $title ) : ?><h2><?php echo esc_html( $title ); ?></h2><?php endif; ?>
	<?php if ( $message ) : ?><p><?php echo esc_html( $message ); ?></p><?php endif; ?>
</section>
