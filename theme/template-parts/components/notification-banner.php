<?php
/**
 * Narrow, dismissible portal notification banner.
 *
 * Only the newest unread notification is shown. No unread notification means
 * no banner. The close control is the same action as acknowledging the
 * notification: it marks the business event read. The details link still takes
 * the reader to the area the notification is about.
 *
 * @package DelnavazanTheme
 */

$items   = isset( $args['items'] ) && is_array( $args['items'] ) ? $args['items'] : array();
$role    = isset( $args['role'] ) && 'teacher' === $args['role'] ? 'teacher' : 'student';
$actions = isset( $args['actions'] ) && is_array( $args['actions'] ) ? $args['actions'] : array();
$read_url = (string) ( $actions['read_url'] ?? '' );
$nonce    = (string) ( $actions['read_nonce'] ?? '' );
$item = null;
foreach ( $items as $candidate ) {
	if ( is_array( $candidate ) && ! empty( $candidate['is_unread'] ) ) {
		$item = $candidate;
		break;
	}
}
if ( ! $item ) {
	return;
}
$id = (int) ( $item['id'] ?? 0 );
$title = trim( (string) ( $item['title'] ?? '' ) );
$message = trim( (string) ( $item['message'] ?? '' ) );
if ( '' === $title && '' === $message ) {
	return;
}
$link = (string) ( $item['link_url'] ?? '' );
$when = (string) ( $item['date'] ?? '' );
$scope = 'teacher' === $role ? 'داشبورد مدرس' : 'حساب هنرجو';
?>
<aside id="dzn-notification-banner" class="dzn-notification-banner" role="status" aria-live="polite" data-dzn-notification-banner data-notification-id="<?php echo esc_attr( (string) $id ); ?>">
	<div class="dzn-notification-banner__inner">
		<p class="dzn-notification-banner__eyebrow"><?php echo esc_html( $scope ); ?><?php if ( '' !== $when ) : ?> <span aria-hidden="true">·</span> <time><?php echo esc_html( $when ); ?></time><?php endif; ?></p>
		<div class="dzn-notification-banner__content">
			<?php if ( '' !== $title ) : ?><strong><?php echo esc_html( $title ); ?></strong><?php endif; ?>
			<?php if ( '' !== $message ) : ?><span><?php echo esc_html( $message ); ?></span><?php endif; ?>
		</div>
		<div class="dzn-notification-banner__actions">
			<?php if ( '' !== $link ) : ?><a class="dzn-notification-banner__link" href="<?php echo esc_url( $link ); ?>"><?php esc_html_e( 'مشاهدهٔ جزئیات', 'delnavazan-theme' ); ?></a><?php endif; ?>
			<?php if ( $id > 0 && '' !== $read_url && '' !== $nonce ) : ?>
				<form method="post" action="<?php echo esc_url( $read_url ); ?>">
					<input type="hidden" name="action" value="dzn_portal_notification_read">
					<input type="hidden" name="notification" value="<?php echo esc_attr( (string) $id ); ?>">
					<?php dzn_theme_nonce_field( 'dzn_portal_notification_read' ); ?>
					<button class="dzn-notification-banner__close" type="submit" data-dzn-notification-close aria-label="<?php esc_attr_e( 'خوانده شد و بستن اعلان', 'delnavazan-theme' ); ?>" title="<?php esc_attr_e( 'خوانده شد و بستن اعلان', 'delnavazan-theme' ); ?>"><span class="dzn-notification-banner__dismiss-label"><?php esc_html_e( 'خوانده شد', 'delnavazan-theme' ); ?></span><span aria-hidden="true">×</span></button>
				</form>
			<?php else : ?>
				<button class="dzn-notification-banner__close" type="button" data-dzn-notification-close aria-label="<?php esc_attr_e( 'بستن اعلان', 'delnavazan-theme' ); ?>"><span aria-hidden="true">×</span></button>
			<?php endif; ?>
		</div>
	</div>
</aside>
