<?php
/**
 * Reusable recent/past class history.
 *
 * @package DelnavazanTheme
 */

$items = isset( $args['items'] ) && is_array( $args['items'] ) ? $args['items'] : array();
$account = ! empty( $args['account'] );
?>
<section class="dzn-portal-section dzn-history" aria-labelledby="<?php echo $account ? 'dzn-past-classes-title' : 'dzn-recent-history-title'; ?>">
	<div class="dzn-portal-section__heading">
		<div>
			<p class="dzn-portal-kicker"><?php echo esc_html( $account ? 'سابقهٔ آموزشی' : 'مرور کوتاه' ); ?></p>
			<h2 id="<?php echo $account ? 'dzn-past-classes-title' : 'dzn-recent-history-title'; ?>"><?php echo esc_html( $account ? 'کلاس‌های گذشته' : 'کلاس‌های اخیر' ); ?></h2>
		</div>
	</div>
	<?php if ( ! $items ) : ?>
		<p class="dzn-portal-empty"><?php esc_html_e( 'هنوز سابقه‌ای برای نمایش دریافت نشده است.', 'delnavazan-theme' ); ?></p>
	<?php else : ?>
		<div class="dzn-history__list">
			<?php foreach ( $items as $item ) : ?>
				<article class="dzn-history-row">
					<div class="dzn-history-row__date"><?php echo esc_html( $item['date'] ?? '' ); ?></div>
					<div class="dzn-history-row__main">
						<h3><?php echo esc_html( $item['title'] ?? '' ); ?></h3>
						<?php if ( ! empty( $item['detail'] ) ) : ?><p><?php echo esc_html( $item['detail'] ); ?></p><?php endif; ?>
					</div>
					<p class="dzn-status dzn-status--<?php echo esc_attr( sanitize_key( $item['tone'] ?? 'muted' ) ); ?>"><?php echo esc_html( $item['outcome'] ?? '' ); ?></p>
				</article>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</section>
