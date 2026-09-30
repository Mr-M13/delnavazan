<?php
/**
 * One-way Delnavazan notifications.
 *
 * @package DelnavazanTheme
 */

$items = isset( $args['items'] ) && is_array( $args['items'] ) ? array_slice( $args['items'], 0, 5 ) : array();
$archive_url = isset( $args['archive_url'] ) ? (string) $args['archive_url'] : '';
?>
<section class="dzn-portal-section dzn-notifications" aria-labelledby="dzn-notifications-title">
	<div class="dzn-portal-section__heading">
		<div>
			<p class="dzn-portal-kicker"><?php esc_html_e( 'یک‌طرفه از دلنوازان', 'delnavazan-theme' ); ?></p>
			<h2 id="dzn-notifications-title"><?php esc_html_e( 'اعلان‌های اخیر', 'delnavazan-theme' ); ?></h2>
		</div>
		<?php if ( $archive_url ) : ?><a class="dzn-portal-text-action" href="<?php echo esc_url( $archive_url ); ?>"><?php esc_html_e( 'مشاهدهٔ همهٔ اعلان‌ها', 'delnavazan-theme' ); ?></a><?php endif; ?>
	</div>
	<?php if ( ! $items ) : ?>
		<p class="dzn-portal-empty"><?php esc_html_e( 'اعلان تازه‌ای وجود ندارد.', 'delnavazan-theme' ); ?></p>
	<?php else : ?>
		<ol class="dzn-notifications__list">
			<?php foreach ( $items as $item ) : ?>
				<li>
					<time><?php echo esc_html( $item['date'] ?? '' ); ?></time>
					<div><h3><?php echo esc_html( $item['title'] ?? '' ); ?></h3><p><?php echo esc_html( $item['message'] ?? '' ); ?></p></div>
				</li>
			<?php endforeach; ?>
		</ol>
	<?php endif; ?>
	<p class="dzn-portal-help"><?php esc_html_e( 'این بخش پیام‌رسان نیست و پاسخ مستقیم ندارد.', 'delnavazan-theme' ); ?></p>
</section>
