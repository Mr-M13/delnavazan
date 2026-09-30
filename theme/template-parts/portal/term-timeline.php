<?php
/**
 * Current Term progress and separately named replacement/remedial facts.
 *
 * @package DelnavazanTheme
 */

$term = isset( $args['term'] ) && is_array( $args['term'] ) ? $args['term'] : array();

if ( ! $term ) {
	return;
}

$completed = isset( $term['completed'] ) ? max( 0, (int) $term['completed'] ) : 0;
$total = isset( $term['total'] ) ? max( 1, (int) $term['total'] ) : 1;
$completed = min( $completed, $total );
?>
<section class="dzn-portal-section dzn-term" aria-labelledby="dzn-term-title">
	<div class="dzn-portal-section__heading">
		<div>
			<p class="dzn-portal-kicker"><?php esc_html_e( 'مسیر یادگیری', 'delnavazan-theme' ); ?></p>
			<h2 id="dzn-term-title"><?php echo esc_html( $term['title'] ?? 'ترم جاری' ); ?></h2>
		</div>
		<p class="dzn-term__count"><?php echo esc_html( $term['current_label'] ?? '' ); ?></p>
	</div>
	<progress class="dzn-term__progress" value="<?php echo esc_attr( $completed ); ?>" max="<?php echo esc_attr( $total ); ?>"><?php echo esc_html( sprintf( '%1$d از %2$d', $completed, $total ) ); ?></progress>
	<ol class="dzn-term__markers" aria-label="<?php esc_attr_e( 'جلسه‌های ترم', 'delnavazan-theme' ); ?>">
		<?php for ( $session = 1; $session <= $total; $session++ ) : ?>
			<?php $marker = $session <= $completed ? 'complete' : ( $session === $completed + 1 ? 'current' : 'remaining' ); ?>
			<li class="is-<?php echo esc_attr( $marker ); ?>"><span class="screen-reader-text"><?php echo esc_html( sprintf( 'جلسهٔ %d — %s', $session, 'complete' === $marker ? 'برگزار شده' : ( 'current' === $marker ? 'پیش رو' : 'باقی‌مانده' ) ) ); ?></span></li>
		<?php endfor; ?>
	</ol>
	<?php if ( ! empty( $term['next_label'] ) ) : ?><p class="dzn-term__next"><?php echo esc_html( $term['next_label'] ); ?></p><?php endif; ?>
	<div class="dzn-term__status-grid">
		<div class="dzn-status-row">
			<p class="dzn-status-row__label"><?php esc_html_e( 'جلسهٔ جایگزین معمول', 'delnavazan-theme' ); ?></p>
			<p><?php echo esc_html( $term['normal_replacement'] ?? 'اطلاعاتی ثبت نشده است' ); ?></p>
		</div>
		<div class="dzn-status-row dzn-status-row--<?php echo esc_attr( sanitize_key( $term['academy_owed_tone'] ?? 'muted' ) ); ?>">
			<p class="dzn-status-row__label"><?php esc_html_e( 'جلسهٔ جبرانیِ بدهکار آموزشگاه', 'delnavazan-theme' ); ?></p>
			<p><?php echo esc_html( $term['academy_owed_remedial'] ?? 'اطلاعاتی ثبت نشده است' ); ?></p>
		</div>
	</div>
</section>
