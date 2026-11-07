<?php
/**
 * Compact current-Term summary.
 *
 * Every figure is a Platform read-model value: the scheduled Term total, the
 * completed/remaining sessions derived from canonical delivery facts, and the
 * replacement allowance projected from the canonical Enrolment. The template
 * never owns an entitlement or a static term allowance.
 *
 * @package DelnavazanTheme
 */

$term = isset( $args['term'] ) && is_array( $args['term'] ) ? $args['term'] : array();

if ( ! $term ) {
	return;
}

$completed = isset( $term['completed'] ) ? max( 0, (int) $term['completed'] ) : 0;
$recorded = isset( $term['recorded'] ) ? max( 0, (int) $term['recorded'] ) : ( isset( $term['total'] ) ? max( 0, (int) $term['total'] ) : 0 );
$total = isset( $term['total'] ) ? max( 1, (int) $term['total'] ) : 12;
$completed = min( $completed, $total );
$instance_id = isset( $args['id'] ) ? sanitize_html_class( (string) $args['id'] ) : 'dzn-term';
if ( '' === $instance_id ) {
	$instance_id = 'dzn-term';
}
$title_id = $instance_id . '-title';
$replacements_title_id = $instance_id . '-replacements-title';
$progress_label = trim( (string) ( $term['progress_label'] ?? '' ) );
if ( '' === $progress_label ) { $progress_label = 'پیشرفت دوره'; }
$completed_label = (string) ( $term['completed_label'] ?? '' );
$remaining_label = (string) ( $term['remaining_label'] ?? '' );
$cancelled_label = (string) ( $term['cancelled_label'] ?? '' );
$replacement_label = (string) ( $term['replacement_label'] ?? '' );
?>
<section class="dzn-portal-section dzn-term dzn-term--compact" aria-labelledby="<?php echo esc_attr( $title_id ); ?>">
	<div class="dzn-portal-section__heading dzn-term__heading">
		<div>
			<p class="dzn-portal-kicker"><?php echo esc_html( $progress_label ); ?></p>
			<h2 id="<?php echo esc_attr( $title_id ); ?>"><?php echo esc_html( $term['title'] ?? 'ترم جاری' ); ?></h2>
		</div>
		<?php if ( ! empty( $term['current_label'] ) ) : ?><p class="dzn-term__count"><?php echo esc_html( (string) $term['current_label'] ); ?></p><?php endif; ?>
	</div>
	<?php if ( $recorded < 1 ) : ?>
		<p class="dzn-portal-empty"><?php esc_html_e( 'ثبت‌نام شما فعال است، اما برنامهٔ جلسه‌ها هنوز از سوی دلنوازان اعلام نشده است. به‌محض نهایی‌شدن زمان‌ها، همین‌جا نمایش داده می‌شود.', 'delnavazan-theme' ); ?></p>
	<?php else : ?>
	<ol class="dzn-term__markers" style="--dzn-term-count: <?php echo (int) $total; ?>" aria-label="<?php echo esc_attr( sprintf( 'پیشرفت ترم: %1$d از %2$d جلسه', $completed, $total ) ); ?>">
		<?php for ( $session = 1; $session <= $total; $session++ ) : ?>
			<?php $marker = $session <= $completed ? 'complete' : ( $session === $completed + 1 ? 'current' : 'remaining' ); ?>
			<li class="is-<?php echo esc_attr( $marker ); ?>"<?php echo 'current' === $marker ? ' aria-current="step"' : ''; ?>><span class="screen-reader-text"><?php echo esc_html( sprintf( 'جلسهٔ %d — %s', $session, 'complete' === $marker ? 'برگزار شده' : ( 'current' === $marker ? 'پیش رو' : 'باقی‌مانده' ) ) ); ?></span></li>
		<?php endfor; ?>
	</ol>
	<dl class="dzn-term__compact-summary">
		<div class="dzn-term__compact-fact dzn-term__compact-fact--lessons">
			<dt><?php esc_html_e( 'جلسه‌های برگزارشده', 'delnavazan-theme' ); ?></dt>
			<dd><?php echo esc_html( $completed_label ); ?></dd>
		</div>
		<div class="dzn-term__compact-fact dzn-term__compact-fact--remaining">
			<dt><?php esc_html_e( 'مانده', 'delnavazan-theme' ); ?></dt>
			<dd><?php echo esc_html( $remaining_label ); ?></dd>
		</div>
		<div class="dzn-term__compact-fact dzn-term__compact-fact--replacement">
			<dt><?php esc_html_e( 'جبرانی', 'delnavazan-theme' ); ?></dt>
			<dd><?php echo esc_html( $replacement_label ); ?></dd>
		</div>
		<?php if ( '' !== $cancelled_label ) : ?>
			<div class="dzn-term__compact-fact dzn-term__compact-fact--cancelled">
				<dt><?php esc_html_e( 'لغو شده', 'delnavazan-theme' ); ?></dt>
				<dd><?php echo esc_html( $cancelled_label ); ?></dd>
			</div>
		<?php endif; ?>
	</dl>
	<?php if ( ! empty( $term['next_label'] ) ) : ?><p class="dzn-term__next"><?php echo esc_html( (string) $term['next_label'] ); ?></p><?php endif; ?>
	<?php $replacement_limit = trim( (string) ( $term['replacement_limit_message'] ?? '' ) ); ?>
	<?php if ( '' !== $replacement_limit ) : ?>
		<p class="dzn-portal-action-status dzn-portal-action-status--warning" role="status"><?php echo esc_html( $replacement_limit ); ?></p>
	<?php endif; ?>
	<?php $replacement_items = is_array( $term['replacement_items'] ?? null ) ? $term['replacement_items'] : array(); ?>
	<?php if ( $replacement_items ) : ?>
		<details class="dzn-term__replacements dzn-term__replacements--compact" id="<?php echo esc_attr( $replacements_title_id ); ?>">
			<summary><?php esc_html_e( 'جلسه‌های جایگزین', 'delnavazan-theme' ); ?></summary>
			<ul>
				<?php foreach ( $replacement_items as $replacement_item ) : ?>
					<li>
						<?php if ( ! empty( $replacement_item['when'] ) ) : ?><strong><?php echo esc_html( (string) $replacement_item['when'] ); ?></strong> · <?php endif; ?>
						<span class="dzn-status dzn-status--<?php echo ! empty( $replacement_item['confirmed'] ) ? 'success' : 'warning'; ?>"><?php echo esc_html( (string) ( $replacement_item['status'] ?? '' ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		</details>
	<?php endif; ?>
	<?php endif; ?>
</section>
