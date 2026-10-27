<?php
/**
 * Teacher statement presentation.
 *
 * Amounts are returned by TeacherStatementReadService in integer minor units.
 * The Theme formats them with the currency exponent; it never infers an amount
 * from lessons and never prints the raw minor-unit integer as if it were money.
 *
 * @package DelnavazanTheme
 */

$m          = $args['model'] ?? array();
$statements = is_array( $m['statements'] ?? null ) ? $m['statements'] : array();
$state      = (string) ( $m['finance_state'] ?? 'unavailable' );
$states     = array(
	'issued'     => 'صادر شده',
	'draft'      => 'پیش‌نویس',
	'superseded' => 'جایگزین شده',
	'withdrawn' => 'لغو شده',
);
$format_money = static function ( int $minor, string $currency ): string {
	if ( class_exists( '\Delnavazan\Platform\Core\Application\Finance\Pricing\PricingMoney' ) ) {
		try { return \Delnavazan\Platform\Core\Application\Finance\Pricing\PricingMoney::format( $minor, $currency, true ); } catch ( Throwable ) {}
	}
	return number_format( $minor ) . ' ' . $currency;
};
?>
<section class="dzn-tp-section" aria-labelledby="teacher-finance">
	<h2 id="teacher-finance">پرداخت‌ها و صورت‌حساب‌ها</h2>
	<?php if ( 'available' === $state && $statements ) : ?>
		<ul class="dzn-tp-rule-list">
			<?php foreach ( $statements as $statement ) : ?>
				<?php
				$currency = (string) ( $statement['currency'] ?? '' );
				$period   = (string) ( $statement['period_label'] ?? '' );
				if ( '' === $period ) {
					$period = trim( (string) ( $statement['period_start_utc'] ?? '' ) . ' – ' . (string) ( $statement['period_end_utc'] ?? '' ) );
				}
				$state_label = $states[ (string) ( $statement['state'] ?? '' ) ] ?? 'وضعیت نامشخص';
				?>
				<li>
					<strong><?php echo esc_html( $period ); ?></strong>
					<span> · <?php echo esc_html( $state_label ); ?></span>
					<span> · <?php echo esc_html( (string) (int) ( $statement['payable_line_count'] ?? 0 ) ); ?> ردیف قابل پرداخت</span>
					<span> · <?php echo esc_html( $format_money( (int) ( $statement['payable_amount_minor'] ?? 0 ), $currency ) ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php elseif ( 'empty' === $state ) : ?>
		<p>هنوز صورت‌حسابی برای نمایش ثبت نشده است.</p>
	<?php else : ?>
		<p>نمای مالی مدرس در حال حاضر در دسترس نیست. مبلغ یا وضعیت پرداختی از روی کلاس‌ها حدس زده نمی‌شود.</p>
	<?php endif; ?>
</section>
