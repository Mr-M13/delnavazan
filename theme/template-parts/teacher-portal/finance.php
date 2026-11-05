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
		<?php
		$statementService = class_exists( '\Delnavazan\Platform\Core\Application\Finance\Read\TeacherStatementReadService' ) ? new \Delnavazan\Platform\Core\Application\Finance\Read\TeacherStatementReadService() : null;
		?>
		<ul class="dzn-tp-rule-list">
			<?php foreach ( $statements as $statement ) : ?>
				<?php
				$currency = (string) ( $statement['currency'] ?? '' );
				$period   = (string) ( $statement['period_label'] ?? '' );
				if ( '' === $period ) {
					$period = trim( (string) ( $statement['period_start_utc'] ?? '' ) . ' – ' . (string) ( $statement['period_end_utc'] ?? '' ) );
				}
				$state_label = $states[ (string) ( $statement['state'] ?? '' ) ] ?? 'وضعیت نامشخص';
				$statementId = (int) ( $statement['statement_id'] ?? 0 );
				$lines = array();
				if ( $statementService !== null && $statementId > 0 ) {
					try { $lines = $statementService->statementLines( $statementId, true ); } catch ( \Throwable ) { $lines = array(); }
				}
				?>
				<li>
					<strong><?php echo esc_html( $period ); ?></strong>
					<span> · #<?php echo esc_html( (string) $statementId ); ?></span>
					<span> · <?php echo esc_html( $state_label ); ?></span>
					<?php if ( ! empty( $statement['issued_at'] ) ) : ?>
						<span> · صادر شده: <?php echo esc_html( $format_instant( (string) $statement['issued_at'] ) ); ?></span>
					<?php endif; ?>
					<span> · <?php echo esc_html( (string) (int) ( $statement['payable_line_count'] ?? 0 ) ); ?> ردیف قابل پرداخت</span>
					<span> · <?php echo esc_html( $format_money( (int) ( $statement['payable_amount_minor'] ?? 0 ), $currency ) ); ?></span>
					<?php if ( (int) ( $statement['golden_hour_bonus_minor'] ?? 0 ) > 0 ) : ?>
						<span class="dzn-tp-golden-badge"><?php echo esc_html( sprintf( 'Golden hour +%s', $format_money( (int) $statement['golden_hour_bonus_minor'], $currency ) ) ); ?></span>
					<?php endif; ?>
					<?php if ( $lines ) : ?>
						<table class="dzn-tp-statement-lines">
							<thead>
								<tr>
									<th>تاریخ</th>
									<th>دوره</th>
									<th>مدت</th>
									<th>نرخ پایه</th>
									<th>فوق‌العاده</th>
									<th>مبلغ نهایی</th>
									<th>وضعیت</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $lines as $line ) : ?>
									<tr>
										<td><?php echo esc_html( (string) ( $line['lesson_date'] ?? '' ) ); ?></td>
										<td><?php echo esc_html( (string) ( $line['course'] ?? '' ) ); ?></td>
										<td><?php echo esc_html( (string) (int) ( $line['duration_minutes'] ?? 0 ) ); ?> دقیقه</td>
										<td><?php echo esc_html( (string) ( $line['base_amount'] ?? '' ) ); ?></td>
										<td><?php echo esc_html( (string) ( $line['bonus_amount'] ?? '' ) ); ?></td>
										<td><strong><?php echo esc_html( (string) ( $line['amount'] ?? '' ) ); ?></strong></td>
										<td><?php echo esc_html( (string) ( $line['status'] ?? '' ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-top:8px">
						<input type="hidden" name="action" value="dzn_teacher_statement_download">
						<input type="hidden" name="statement_id" value="<?php echo (int) $statementId; ?>">
						<?php wp_nonce_field( 'dzn_teacher_statement_download' ); ?>
						<button class="button" type="submit">دانلود صورت‌حساب بر اساس الگو</button>
					</form>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php elseif ( 'empty' === $state ) : ?>
		<p>هنوز صورت‌حسابی برای نمایش ثبت نشده است. پس از تکمیل کلاس‌ها و تایید پرداخت‌ها، صورت‌حساب ایجاد می‌شود.</p>
	<?php else : ?>
		<p>نمای مالی مدرس در حال حاضر در دسترس نیست. اگر نشست شما منقضی شده است، لطفاً دوباره وارد شوید. مبلغ یا وضعیت پرداختی از روی کلاس‌ها حدس زده نمی‌شود.</p>
	<?php endif; ?>
</section>

<?php dzn_theme_component( 'tuition-allocation', array( 'model' => $m['tuition_allocations'] ?? array(), 'id' => 'teacher-tuition-allocation' ) ); ?>
