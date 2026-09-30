<?php
/**
 * Dismissible, session-local announcement presentation.
 *
 * @package DelnavazanTheme
 */

$announcement = isset( $args['announcement'] ) && is_array( $args['announcement'] ) ? $args['announcement'] : array();
$id = isset( $announcement['id'] ) ? sanitize_key( $announcement['id'] ) : '';
$title = isset( $announcement['title'] ) ? (string) $announcement['title'] : '';

if ( ! $title ) {
	return;
}
?>
<aside class="dzn-portal-announcement" data-dzn-announcement="<?php echo esc_attr( $id ); ?>" aria-labelledby="dzn-announcement-title-<?php echo esc_attr( $id ); ?>">
	<div>
		<?php if ( ! empty( $announcement['eyebrow'] ) ) : ?><p class="dzn-portal-kicker"><?php echo esc_html( $announcement['eyebrow'] ); ?></p><?php endif; ?>
		<h2 id="dzn-announcement-title-<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $title ); ?></h2>
		<?php if ( ! empty( $announcement['message'] ) ) : ?><p><?php echo esc_html( $announcement['message'] ); ?></p><?php endif; ?>
	</div>
	<button class="dzn-portal-icon-button" type="button" data-dzn-announcement-dismiss aria-label="<?php esc_attr_e( 'بستن این اعلان برای این نشست', 'delnavazan-theme' ); ?>">
		<span aria-hidden="true">×</span>
	</button>
</aside>
