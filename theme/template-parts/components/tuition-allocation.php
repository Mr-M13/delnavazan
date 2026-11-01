<?php
/**
 * Shared illustrative tuition-allocation component for authenticated portals.
 *
 * The component deliberately exposes percentages only. It never reads a term, a
 * paid amount or a portal-specific allocation row, so the Teacher and Student
 * portals always show the same categories and the same shares. The model
 * argument is accepted for backwards compatibility and intentionally ignored.
 */
$id = sanitize_key( (string) ( $args['id'] ?? 'tuition-allocation' ) );
$categories = array();
if ( class_exists( '\Delnavazan\Platform\Core\Application\Finance\TuitionAllocationEstimator' ) ) {
	try {
		$categories = \Delnavazan\Platform\Core\Application\Finance\TuitionAllocationEstimator::categories();
	} catch ( Throwable ) {
		$categories = array();
	}
}
?>
<section class="dzn-allocation dzn-allocation--transparency" aria-labelledby="<?php echo esc_attr( $id ); ?>">
	<div class="dzn-allocation__heading">
		<div><p class="dzn-allocation__kicker">اطلاعات مالی</p><h2 id="<?php echo esc_attr( $id ); ?>">تخصیص برآوردی شهریه</h2></div>
		<span class="dzn-allocation__estimate">برآوردی</span>
	</div>
	<p class="dzn-allocation__notice" role="note">این اطلاعات صرفاً برآوردی است و فقط سهم درصدی از شهریه را نشان می‌دهد؛ نه صورت‌حساب، سند هزینه، مالیات پرداخت‌شده یا تسویه‌حساب مدرس. درآمد و هزینه واقعی هر دوره می‌تواند متفاوت باشد.</p>
	<?php if ( $categories ) : ?>
		<details class="dzn-allocation__details">
			<summary>مشاهدهٔ درصدهای برآوردی تخصیص</summary>
			<p class="dzn-allocation__intro">شهریهٔ دریافتی، پس از کسر سهم دلنوازان، به نسبت‌های زیر برای ادارهٔ آموزش، پشتیبانی و رشد دلنوازان در نظر گرفته می‌شود. این نسبت‌ها یک برآورد برنامه‌ریزی‌شده است، نه گزارش هزینهٔ حسابرسی‌شده.</p>
			<div class="dzn-allocation__bar" aria-hidden="true">
				<?php foreach ( $categories as $row ) : ?>
					<span style="--dzn-share:<?php echo esc_attr( (string) ( $row['basis_points'] ?? 0 ) ); ?>;--dzn-share-color:<?php echo esc_attr( (string) ( $row['color'] ?? '#777' ) ); ?>"></span>
				<?php endforeach; ?>
			</div>
			<div class="dzn-allocation__table-wrap" tabindex="0" role="region" aria-label="جدول درصدهای برآوردی تخصیص شهریه">
				<table class="dzn-allocation__table">
					<thead><tr><th scope="col">دسته</th><th scope="col">درصد</th></tr></thead>
					<tbody>
					<?php foreach ( $categories as $row ) : ?>
						<tr>
							<th scope="row"><i style="--dzn-share-color:<?php echo esc_attr( (string) ( $row['color'] ?? '#777' ) ); ?>" aria-hidden="true"></i><?php echo esc_html( (string) ( $row['label_fa'] ?? '' ) ); ?></th>
							<td dir="ltr"><?php echo esc_html( (string) ( $row['percentage_label'] ?? '0' ) ); ?>%</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
					<tfoot><tr><th scope="row">جمع</th><td dir="ltr">100.0%</td></tr></tfoot>
				</table>
			</div>
			<p class="dzn-allocation__gst-note" role="note">درصد GST سهم برآوردی از درآمد است و به‌معنای مالیات قابل پرداخت یا مالیات پرداخت‌شده نیست؛ محاسبهٔ واقعی GST به قوانین مالیاتی و وضعیت ثبت کسب‌وکار بستگی دارد.</p>
		</details>
	<?php else : ?>
		<p class="dzn-allocation__empty" role="status">جزئیات برآوردی تخصیص شهریه در حال حاضر در دسترس نیست.</p>
	<?php endif; ?>
</section>
