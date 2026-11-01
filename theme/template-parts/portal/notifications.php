<?php
/**
 * Delnavazan website notifications for one portal user.
 *
 * @package DelnavazanTheme
 */

$items     = isset( $args['items'] ) && is_array( $args['items'] ) ? array_slice( $args['items'], 0, 20 ) : array();
$archive   = isset( $args['archive_url'] ) ? (string) $args['archive_url'] : '';
$available = ! empty( $args['available'] );
$unread    = isset( $args['unread'] ) ? (int) $args['unread'] : 0;
$actions   = isset( $args['actions'] ) && is_array( $args['actions'] ) ? $args['actions'] : array();
$read_url  = (string) ( $actions['read_url'] ?? '' );
$nonce     = (string) ( $actions['read_nonce'] ?? '' );
?>
<section id="dzn-portal-notifications" class="dzn-portal-section dzn-notifications" aria-labelledby="dzn-notifications-title">
	<div class="dzn-portal-section__heading">
		<div>
			<p class="dzn-portal-kicker"><?php esc_html_e( 'پیام‌های حساب شما', 'delnavazan-theme' ); ?></p>
			<h2 id="dzn-notifications-title"><?php esc_html_e( 'اعلان‌ها', 'delnavazan-theme' ); ?><?php if ( $unread > 0 ) : ?> <span class="dzn-notifications__count"><?php echo esc_html( number_format_i18n( $unread ) ); ?> تازه</span><?php endif; ?></h2>
		</div>
		<?php if ( $archive ) : ?><a class="dzn-portal-text-action" href="<?php echo esc_url( $archive ); ?>"><?php esc_html_e( 'مشاهدهٔ همهٔ اعلان‌ها', 'delnavazan-theme' ); ?></a><?php endif; ?>
	</div>
	<?php if ( ! $available ) : ?>
		<p class="dzn-portal-empty"><?php esc_html_e( 'این بخش هنوز به منبع اعلان معتبر متصل نشده است؛ بنابراین «بدون اعلان» به‌معنای نبودِ پیام نیست.', 'delnavazan-theme' ); ?></p>
	<?php elseif ( ! $items ) : ?>
		<p class="dzn-portal-empty"><?php esc_html_e( 'اعلان تازه‌ای وجود ندارد.', 'delnavazan-theme' ); ?></p>
	<?php else : ?>
		<?php if ( $unread > 0 && $read_url !== '' && $nonce !== '' ) : ?>
			<form class="dzn-notifications__all" method="post" action="<?php echo esc_url( $read_url ); ?>">
				<input type="hidden" name="action" value="dzn_portal_notification_read_all">
				<?php dzn_theme_nonce_field( 'dzn_portal_notification_read_all' ); ?>
				<button class="dzn-button dzn-button--secondary dzn-notifications__all-button" type="submit"><?php esc_html_e( 'همه را خوانده‌شده کن', 'delnavazan-theme' ); ?></button>
			</form>
		<?php endif; ?>
		<ol class="dzn-notifications__list">
			<?php foreach ( $items as $item ) : ?>
				<?php $is_unread = ! empty( $item['is_unread'] ); ?>
				<li class="dzn-notifications__item<?php echo $is_unread ? ' is-unread' : ''; ?>">
					<time<?php if ( ! empty( $item['created_at'] ) ) : ?> datetime="<?php echo esc_attr( (string) $item['created_at'] ); ?>"<?php endif; ?>><?php echo esc_html( (string) ( $item['date'] ?? '' ) ); ?></time>
					<div class="dzn-notifications__body">
						<h3><?php echo esc_html( (string) ( $item['title'] ?? '' ) ); ?><?php if ( $is_unread ) : ?><span class="dzn-notifications__badge"><?php esc_html_e( 'تازه', 'delnavazan-theme' ); ?></span><span class="dzn-notifications__dot" aria-hidden="true"></span><?php endif; ?></h3>
						<p><?php echo esc_html( (string) ( $item['message'] ?? '' ) ); ?></p>
						<?php if ( ! empty( $item['link_url'] ) || ( $is_unread && $read_url !== '' && $nonce !== '' ) ) : ?>
							<div class="dzn-notifications__actions">
								<?php if ( ! empty( $item['link_url'] ) ) : ?>
									<a class="dzn-portal-text-action" href="<?php echo esc_url( (string) $item['link_url'] ); ?>"><?php esc_html_e( 'مشاهدهٔ جزئیات', 'delnavazan-theme' ); ?></a>
								<?php endif; ?>
								<?php if ( $is_unread && $read_url !== '' && $nonce !== '' ) : ?>
									<form method="post" action="<?php echo esc_url( $read_url ); ?>">
										<input type="hidden" name="action" value="dzn_portal_notification_read">
										<input type="hidden" name="notification" value="<?php echo esc_attr( (string) (int) ( $item['id'] ?? 0 ) ); ?>">
										<?php dzn_theme_nonce_field( 'dzn_portal_notification_read' ); ?>
										<button class="dzn-portal-text-action" type="submit"><?php esc_html_e( 'خوانده شد', 'delnavazan-theme' ); ?></button>
									</form>
								<?php endif; ?>
							</div>
						<?php endif; ?>
					</div>
				</li>
			<?php endforeach; ?>
		</ol>
	<?php endif; ?>
	<p class="dzn-portal-help"><?php esc_html_e( 'این اعلان‌ها فقط وضعیت را نشان می‌دهند؛ برای تأیید یا تغییر کلاس، پرداخت یا درخواست، از دکمهٔ همان صفحهٔ کاری استفاده کنید.', 'delnavazan-theme' ); ?></p>
</section>
