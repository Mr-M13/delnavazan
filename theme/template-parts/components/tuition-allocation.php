<?php
/** Shared illustrative tuition-allocation component for authenticated portals. */
$model = is_array( $args['model'] ?? null ) ? $args['model'] : array();
$state = (string) ( $model['state'] ?? 'unavailable' );
$items = is_array( $model['items'] ?? null ) ? $model['items'] : array();
$id = sanitize_key( (string) ( $args['id'] ?? 'tuition-allocation' ) );
$format_money = static function ( int $minor, string $currency ): string {
	if ( class_exists( '\Delnavazan\Platform\Core\Application\Finance\Pricing\PricingMoney' ) ) {
		try { return \Delnavazan\Platform\Core\Application\Finance\Pricing\PricingMoney::format( $minor, $currency, true ); } catch ( Throwable ) {}
	}
	return number_format_i18n( $minor ) . ' ' . $currency;
};
?>
<section class="dzn-allocation" aria-labelledby="<?php echo esc_attr( $id ); ?>">
	<div class="dzn-allocation__heading">
		<div><p class="dzn-allocation__kicker">اطلاعات مالی</p><h2 id="<?php echo esc_attr( $id ); ?>">جزئیات برآوردی تخصیص شهریه</h2></div>
		<span class="dzn-allocation__estimate">برآوردی</span>
	</div>
	<p class="dzn-allocation__notice" role="note">این اطلاعات صرفاً برآوردی و جهت آگاهی از نحوه تخصیص شهریه است و لزوماً نشان‌دهنده هزینه‌های واقعی، مالیات پرداخت‌شده یا تسویه‌حساب مدرس نیست.</p>
	<?php if ( 'available' === $state && $items ) : ?>
		<div class="dzn-allocation__terms">
		<?php foreach ( $items as $term_index => $term ) :
			$currency = (string) ( $term['currency'] ?? '' );
			$amount = (int) ( $term['amount_minor'] ?? 0 );
			$allocation = is_array( $term['allocation'] ?? null ) ? $term['allocation'] : array();
			$rows = is_array( $allocation['items'] ?? null ) ? $allocation['items'] : array();
			$term_id = $id . '-term-' . ( $term_index + 1 );
		?>
			<article class="dzn-allocation__term" aria-labelledby="<?php echo esc_attr( $term_id ); ?>">
				<header><div><h3 id="<?php echo esc_attr( $term_id ); ?>"><?php echo esc_html( (string) ( $term['term_reference'] ?? '' ) ); ?></h3><p>مبلغ پرداخت‌شده: <strong><?php echo esc_html( $format_money( $amount, $currency ) ); ?></strong> <bdi><?php echo esc_html( $currency ); ?></bdi></p></div></header>
				<div class="dzn-allocation__bar" aria-hidden="true"><?php foreach ( $rows as $row ) : ?><span style="--dzn-share:<?php echo esc_attr( (string) ( $row['basis_points'] ?? 0 ) ); ?>;--dzn-share-color:<?php echo esc_attr( (string) ( $row['color'] ?? '#777' ) ); ?>"></span><?php endforeach; ?></div>
				<div class="dzn-allocation__table-wrap" tabindex="0" role="region" aria-label="جدول تخصیص برآوردی <?php echo esc_attr( (string) ( $term['term_reference'] ?? '' ) ); ?>">
				<table class="dzn-allocation__table"><thead><tr><th scope="col">دسته</th><th scope="col">درصد</th><th scope="col">مبلغ</th></tr></thead><tbody>
				<?php foreach ( $rows as $row ) : ?><tr><th scope="row"><i style="--dzn-share-color:<?php echo esc_attr( (string) ( $row['color'] ?? '#777' ) ); ?>" aria-hidden="true"></i><?php echo esc_html( (string) ( $row['label_fa'] ?? '' ) ); ?></th><td dir="ltr"><?php echo esc_html( (string) ( $row['percentage_label'] ?? '0.0' ) ); ?>%</td><td><?php echo esc_html( $format_money( (int) ( $row['amount_minor'] ?? 0 ), $currency ) ); ?></td></tr><?php endforeach; ?>
				</tbody><tfoot><tr><th scope="row">جمع</th><td dir="ltr">100.0%</td><td><?php echo esc_html( $format_money( (int) ( $allocation['total_minor'] ?? 0 ), $currency ) ); ?></td></tr></tfoot></table>
				</div>
			</article>
		<?php endforeach; ?>
		</div>
	<?php elseif ( 'empty' === $state ) : ?>
		<p class="dzn-allocation__empty">هنوز ترم پرداخت‌شده‌ای برای نمایش این برآورد وجود ندارد.</p>
	<?php else : ?>
		<p class="dzn-allocation__empty" role="status">جزئیات برآوردی تخصیص شهریه در حال حاضر در دسترس نیست.</p>
	<?php endif; ?>
</section>
