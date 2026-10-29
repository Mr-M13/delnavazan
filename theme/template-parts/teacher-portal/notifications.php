<?php
/**
 * Delnavazan website notifications for the Teacher portal.
 *
 * @package DelnavazanTheme
 */

$items     = isset( $args['items'] ) && is_array( $args['items'] ) ? array_slice( $args['items'], 0, 20 ) : array();
$available = ! empty( $args['available'] );
$unread    = isset( $args['unread'] ) ? (int) $args['unread'] : 0;
$actions   = isset( $args['actions'] ) && is_array( $args['actions'] ) ? $args['actions'] : array();
$read_url  = (string) ( $actions['read_url'] ?? '' );
$nonce     = (string) ( $actions['read_nonce'] ?? '' );
?>
<section id="dzn-tp-notifications" class="dzn-tp-section dzn-notifications" aria-labelledby="dzn-tp-notifications-title">
	<div class="dzn-tp-section__heading">
		<div>
			<p class="dzn-tp-kicker">پیام‌های حساب شما</p>
			<h2 id="dzn-tp-notifications-title">اعلان‌ها<?php if ( $unread > 0 ) : ?> <span class="dzn-notifications__count"><?php echo esc_html( number_format_i18n( $unread ) ); ?> تازه</span><?php endif; ?></h2>
		</div>
	</div>
	<?php if ( ! $available ) : ?>
		<p class="dzn-tp-empty">این بخش هنوز به منبع اعلان معتبر متصل نشده است؛ بنابراین «بدون اعلان» به‌معنای نبودِ پیام نیست.</p>
	<?php elseif ( ! $items ) : ?>
		<p class="dzn-tp-empty">اعلان تازه‌ای وجود ندارد.</p>
	<?php else : ?>
		<?php if ( $unread > 0 && $read_url !== '' && $nonce !== '' ) : ?>
			<form class="dzn-notifications__all" method="post" action="<?php echo esc_url( $read_url ); ?>">
				<input type="hidden" name="action" value="dzn_portal_notification_read_all">
				<?php dzn_theme_nonce_field( 'dzn_portal_notification_read_all' ); ?>
				<button class="dzn-button dzn-button--secondary dzn-notifications__all-button" type="submit">همه را خوانده‌شده کن</button>
			</form>
		<?php endif; ?>
		<ol class="dzn-notifications__list">
			<?php foreach ( $items as $item ) : ?>
				<?php $is_unread = ! empty( $item['is_unread'] ); ?>
				<li class="dzn-notifications__item<?php echo $is_unread ? ' is-unread' : ''; ?>">
					<time<?php if ( ! empty( $item['created_at'] ) ) : ?> datetime="<?php echo esc_attr( (string) $item['created_at'] ); ?>"<?php endif; ?>><?php echo esc_html( (string) ( $item['date'] ?? '' ) ); ?></time>
					<div class="dzn-notifications__body">
						<h3><?php echo esc_html( (string) ( $item['title'] ?? '' ) ); ?><?php if ( $is_unread ) : ?><span class="dzn-notifications__dot" aria-label="خوانده‌نشده"></span><?php endif; ?></h3>
						<p><?php echo esc_html( (string) ( $item['message'] ?? '' ) ); ?></p>
						<?php if ( ! empty( $item['link_url'] ) || ( $is_unread && $read_url !== '' && $nonce !== '' ) ) : ?>
							<div class="dzn-notifications__actions">
								<?php if ( ! empty( $item['link_url'] ) ) : ?>
									<a class="dzn-portal-text-action" href="<?php echo esc_url( (string) $item['link_url'] ); ?>">مشاهدهٔ جزئیات</a>
								<?php endif; ?>
								<?php if ( $is_unread && $read_url !== '' && $nonce !== '' ) : ?>
									<form method="post" action="<?php echo esc_url( $read_url ); ?>">
										<input type="hidden" name="action" value="dzn_portal_notification_read">
										<input type="hidden" name="notification" value="<?php echo esc_attr( (string) (int) ( $item['id'] ?? 0 ) ); ?>">
										<?php dzn_theme_nonce_field( 'dzn_portal_notification_read' ); ?>
										<button class="dzn-portal-text-action" type="submit">خوانده شد</button>
									</form>
								<?php endif; ?>
							</div>
						<?php endif; ?>
					</div>
				</li>
			<?php endforeach; ?>
		</ol>
	<?php endif; ?>
	<p class="dzn-tp-note">این اعلان‌ها فقط وضعیت را نشان می‌دهند؛ تأیید یا تغییر کلاس، پرداخت یا درخواست همچنان از دکمهٔ همان صفحهٔ کاری انجام می‌شود.</p>
</section>
