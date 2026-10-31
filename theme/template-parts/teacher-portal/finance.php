<?php
/**
 * Teacher statement presentation and operational finance snapshot.
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
$summary    = is_array( $m['finance_summary'] ?? null ) ? $m['finance_summary'] : array();
$summary_state = (string) ( $m['finance_summary_state'] ?? 'unavailable' );
$timezone   = (string) ( $m['teacher_timezone'] ?? '' );
$calendar   = (string) ( $m['teacher_calendar'] ?? 'gregorian' );
$locale     = (string) ( $m['teacher_locale'] ?? '' );
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
$format_instant = static function ( string $utc ) use ( $timezone, $calendar, $locale ): string {
	if ( '' === trim( $utc ) ) { return ''; }
	return dzn_theme_platform_calendar_time( $utc, $timezone ?: ( wp_timezone_string() ?: 'UTC' ), $calendar, $locale, 'datetime' );
};
$ending_terms = is_array( $summary['ending_terms'] ?? null ) ? $summary['ending_terms'] : array();
$next_lesson  = is_array( $summary['next_lesson'] ?? null ) ? $summary['next_lesson'] : null;
$hours_month  = number_format_i18n( ( (int) ( $summary['minutes_month'] ?? 0 ) ) / 60, 1 );
?>
<section class="dzn-tp-section" aria-labelledby="teacher-finance-summary">
	<div class="dzn-tp-heading">
		<div>
			<p class="dzn-tp-kicker">نمای کاری</p>
			<h2 id="teacher-finance-summary">وضعیت این ماه</h2>
		</div>
		<?php if ( '' !== $timezone ) : ?><span class="dzn-timezone-label"><span aria-hidden="true">◷</span> <?php echo esc_html( $timezone ); ?></span><?php endif; ?>
	</div>
	<?php if ( 'available' === $summary_state ) : ?>
		<div class="dzn-tp-stats dzn-tp-stats--finance">
			<div><span>هنرجویان فعال</span><strong><?php echo esc_html( number_format_i18n( (int) ( $summary['active_students'] ?? 0 ) ) ); ?></strong></div>
			<div><span>ساعت تدریس این ماه</span><strong><?php echo esc_html( $hours_month ); ?></strong><small>ساعت</small></div>
			<div><span>جلسات این ماه</span><strong><?php echo esc_html( number_format_i18n( (int) ( $summary['lessons_month'] ?? 0 ) ) ); ?></strong><small><?php echo esc_html( sprintf( '%s برگزارشده', number_format_i18n( (int) ( $summary['completed_month'] ?? 0 ) ) ) ); ?></small></div>
			<div><span>کلاس‌های پیش رو</span><strong><?php echo esc_html( number_format_i18n( (int) ( $summary['upcoming_lessons'] ?? 0 ) ) ); ?></strong></div>
			<div><span>درخواست تغییر زمان</span><strong><?php echo esc_html( number_format_i18n( (int) ( $summary['pending_reschedules'] ?? 0 ) ) ); ?></strong><small>در انتظار هنرجو</small></div>
		</div>
		<?php if ( $next_lesson ) : ?>
			<section class="dzn-tp-finance-next" aria-labelledby="teacher-finance-next">
				<h3 id="teacher-finance-next">کلاس بعدی</h3>
				<p><strong><?php echo esc_html( (string) ( $next_lesson['student'] ?? '' ) ); ?></strong><?php if ( '' !== (string) ( $next_lesson['course'] ?? '' ) ) : ?> · <?php echo esc_html( (string) $next_lesson['course'] ); ?><?php endif; ?></p>
				<p class="dzn-tp-help"><?php echo esc_html( $format_instant( (string) ( $next_lesson['starts_at_utc'] ?? '' ) ) ); ?></p>
			</section>
		<?php endif; ?>
		<section class="dzn-tp-finance-terms" aria-labelledby="teacher-finance-terms">
			<h3 id="teacher-finance-terms">ترم‌های نزدیک به پایان</h3>
			<?php if ( ! $ending_terms ) : ?>
				<p class="dzn-tp-help">هیچ ترم جاری‌ای در سه جلسهٔ پایانی نیست.</p>
			<?php else : ?>
				<ul class="dzn-tp-rule-list dzn-tp-finance-terms__list">
					<?php foreach ( $ending_terms as $term ) : ?>
						<li>
							<strong><?php echo esc_html( (string) ( $term['student'] ?? '' ) ); ?></strong>
							<?php if ( '' !== (string) ( $term['course'] ?? '' ) ) : ?><span> · <?php echo esc_html( (string) $term['course'] ); ?></span><?php endif; ?>
							<span> · <?php echo esc_html( sprintf( '%s از %s جلسه مانده', number_format_i18n( (int) ( $term['remaining'] ?? 0 ) ), number_format_i18n( (int) ( $term['total'] ?? 0 ) ) ) ); ?></span>
							<?php if ( '' !== (string) ( $term['last_scheduled_utc'] ?? '' ) ) : ?><span class="dzn-tp-help"> · آخرین جلسه: <?php echo esc_html( $format_instant( (string) $term['last_scheduled_utc'] ) ); ?></span><?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>
	<?php else : ?>
		<p class="dzn-tp-help">نمای کاری این ماه در دسترس نیست. مبلغ یا ساعت تدریس از روی اطلاعات ناقص حدس زده نمی‌شود.</p>
	<?php endif; ?>
</section>

<section class="dzn-tp-section" aria-labelledby="teacher-finance">
	<h2 id="teacher-finance">صورت‌حساب‌ها</h2>
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
					<?php if ( (int) ( $statement['golden_hour_bonus_minor'] ?? 0 ) > 0 ) : ?>
						<span class="dzn-tp-golden-badge"><?php echo esc_html( sprintf( 'Golden hour +%s', $format_money( (int) $statement['golden_hour_bonus_minor'], $currency ) ) ); ?></span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php elseif ( 'empty' === $state ) : ?>
		<p>هنوز صورت‌حسابی برای نمایش ثبت نشده است.</p>
	<?php else : ?>
		<p>نمای مالی مدرس در حال حاضر در دسترس نیست. مبلغ یا وضعیت پرداختی از روی کلاس‌ها حدس زده نمی‌شود.</p>
	<?php endif; ?>
</section>
