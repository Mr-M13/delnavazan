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
$recorded = isset( $term['total'] ) ? max( 0, (int) $term['total'] ) : 0;
$total = max( 1, $recorded );
$completed = min( $completed, $total );

// The heading names a term only when the canonical read model returned one.
$title = isset( $term['title'] ) ? trim( (string) $term['title'] ) : '';

// `total` is the number of canonical lessons the portal actually read. It is not
// the term's lesson allocation, so the copy must not imply a finished term.
$scale_known = ! empty( $term['lesson_allocation_known'] );
$scale_note  = $scale_known
	? 'مقیاس این نوار، سهمیهٔ اعلام‌شدهٔ ترم است.'
	: 'مقیاس این نوار، جلسه‌های ثبت‌شدهٔ ترم است، نه سهمیهٔ کل ترم.';
?>
<section class="dzn-portal-section dzn-term" aria-labelledby="dzn-term-title">
	<div class="dzn-portal-section__heading">
		<div>
			<p class="dzn-portal-kicker"><?php esc_html_e( 'مسیر یادگیری', 'delnavazan-theme' ); ?></p>
			<h2 id="dzn-term-title"><?php echo esc_html( '' !== $title ? $title : 'ترم جاری' ); ?></h2>
		</div>
		<p class="dzn-term__count"><?php echo esc_html( $term['current_label'] ?? '' ); ?></p>
	</div>
	<?php if ( $recorded < 1 ) : ?>
		<p class="dzn-portal-empty"><?php esc_html_e( 'ثبت‌نام شما فعال است، اما برنامهٔ جلسه‌ها هنوز از سوی دلنوازان اعلام نشده است. به‌محض نهایی‌شدن زمان‌ها، همین‌جا نمایش داده می‌شود.', 'delnavazan-theme' ); ?></p>
	<?php else : ?>
	<progress class="dzn-term__progress" value="<?php echo esc_attr( $completed ); ?>" max="<?php echo esc_attr( $total ); ?>"><?php echo esc_html( sprintf( '%1$d از %2$d', $completed, $total ) ); ?></progress>
	<ol class="dzn-term__markers" aria-label="<?php esc_attr_e( 'جلسه‌های ترم', 'delnavazan-theme' ); ?>">
		<?php for ( $session = 1; $session <= $total; $session++ ) : ?>
			<?php $marker = $session <= $completed ? 'complete' : ( $session === $completed + 1 ? 'current' : 'remaining' ); ?>
			<li class="is-<?php echo esc_attr( $marker ); ?>"><span class="screen-reader-text"><?php echo esc_html( sprintf( 'جلسهٔ %d — %s', $session, 'complete' === $marker ? 'برگزار شده' : ( 'current' === $marker ? 'پیش رو' : 'باقی‌مانده' ) ) ); ?></span></li>
		<?php endfor; ?>
	</ol>
	<p class="dzn-term__scale dzn-portal-help"><?php echo esc_html( $scale_note ); ?></p>
	<?php if ( ! empty( $term['next_label'] ) ) : ?><p class="dzn-term__next"><?php echo esc_html( $term['next_label'] ); ?></p><?php endif; ?>
	<?php
	$normal_replacement = isset( $term['normal_replacement'] ) ? trim( (string) $term['normal_replacement'] ) : '';
	$academy_owed       = isset( $term['academy_owed_remedial'] ) ? trim( (string) $term['academy_owed_remedial'] ) : '';
	?>
	<div class="dzn-term__status-grid">
		<div class="dzn-status-row">
			<p class="dzn-status-row__label"><?php esc_html_e( 'جلسهٔ جایگزین معمول', 'delnavazan-theme' ); ?></p>
			<p><?php echo esc_html( '' !== $normal_replacement ? $normal_replacement : 'اطلاعاتی ثبت نشده است' ); ?></p>
		</div>
		<div class="dzn-status-row dzn-status-row--<?php echo esc_attr( sanitize_key( $term['academy_owed_tone'] ?? 'muted' ) ); ?>">
			<p class="dzn-status-row__label"><?php esc_html_e( 'جلسهٔ جبرانیِ بدهکار آموزشگاه', 'delnavazan-theme' ); ?></p>
			<p><?php echo esc_html( '' !== $academy_owed ? $academy_owed : 'اطلاعاتی ثبت نشده است' ); ?></p>
		</div>
	</div>
	<?php endif; ?>
</section>
