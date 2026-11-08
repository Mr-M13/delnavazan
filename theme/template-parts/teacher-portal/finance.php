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
	'paid'       => 'پرداخت شده',
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
$hours_month  = number_format_i18n( ( (int) ( $summary['completed_minutes_month'] ?? $summary['minutes_month'] ?? 0 ) ) / 60, 1 );
$scheduled_hours_month = number_format_i18n( ( (int) ( $summary['scheduled_minutes_month'] ?? 0 ) ) / 60, 1 );
$remuneration = is_array( $summary['remuneration'] ?? null ) ? $summary['remuneration'] : array();
$rate         = is_array( $remuneration['rate'] ?? null ) ? $remuneration['rate'] : array( 'state' => 'unavailable' );
$golden       = is_array( $remuneration['golden_hour'] ?? null ) ? $remuneration['golden_hour'] : array( 'state' => 'unavailable' );
$expected     = is_array( $remuneration['expected_total'] ?? null ) ? $remuneration['expected_total'] : array( 'state' => 'unavailable' );
$format_percentage = static function ( int $basis_points ): string {
	return rtrim( rtrim( number_format( $basis_points / 100, 2, '.', '' ), '0' ), '.' );
};
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
			<div><span>ساعت تدریس این ماه</span><strong><?php echo esc_html( $hours_month ); ?></strong><small><?php echo esc_html( sprintf( 'از %s ساعت برنامه‌ریزی‌شده', $scheduled_hours_month ) ); ?></small></div>
			<div><span>جلسات برگزارشده</span><strong><?php echo esc_html( number_format_i18n( (int) ( $summary['completed_lessons_month'] ?? $summary['completed_month'] ?? 0 ) ) ); ?></strong><small><?php echo esc_html( sprintf( 'از %s جلسهٔ برنامه‌ریزی‌شده', number_format_i18n( (int) ( $summary['scheduled_lessons_month'] ?? $summary['lessons_month'] ?? 0 ) ) ) ); ?></small></div>
			<div><span>کلاس‌های پیش رو</span><strong><?php echo esc_html( number_format_i18n( (int) ( $summary['upcoming_lessons'] ?? 0 ) ) ); ?></strong></div>
			<div><span>درخواست تغییر زمان</span><strong><?php echo esc_html( number_format_i18n( (int) ( $summary['pending_reschedules'] ?? 0 ) ) ); ?></strong><small><?php echo esc_html( sprintf( '%s درخواست در این ماه', number_format_i18n( (int) ( $summary['reschedule_requests_month'] ?? 0 ) ) ) ); ?></small></div>
		</div>
		<section class="dzn-tp-finance-remuneration" aria-labelledby="teacher-finance-remuneration">
			<h3 id="teacher-finance-remuneration">دستمزد و پاداش</h3>
			<ul class="dzn-tp-rule-list dzn-tp-finance-remuneration__list">
				<li>
					<strong>دستمزد توافقی</strong>
					<?php if ( 'available' === (string) ( $rate['state'] ?? '' ) ) : ?>
						<span> · <bdi dir="ltr"><?php echo esc_html( $format_money( (int) $rate['amount_minor'], (string) $rate['currency'] ) ); ?></bdi> برای <?php echo esc_html( (string) ( $rate['unit_label'] ?? 'هر جلسه' ) ); ?></span>
					<?php elseif ( 'rate_ambiguous' === (string) ( $rate['reason'] ?? '' ) ) : ?>
						<span> · چند نرخ فعال هم‌زمان ثبت شده است؛ برای جلوگیری از حدس، نرخ واحد نمایش داده نمی‌شود.</span>
					<?php else : ?>
						<span> · نرخ توافق‌شدهٔ فعالی ثبت نشده است.</span>
					<?php endif; ?>
				</li>
				<li>
					<strong>پاداش ساعت طلایی</strong>
					<?php if ( 'available' === (string) ( $golden['state'] ?? '' ) ) : ?>
						<span> · <?php echo esc_html( number_format_i18n( (int) ( $golden['eligible_minutes'] ?? 0 ) ) ); ?> دقیقهٔ واجد شرایط</span>
						<?php if ( ! empty( $golden['bonus_basis_points_mixed'] ) ) : ?>
							<span> · درصد متغیر بین <bdi dir="ltr"><?php echo esc_html( $format_percentage( (int) $golden['bonus_basis_points_min'] ) ); ?>%</bdi> و <bdi dir="ltr"><?php echo esc_html( $format_percentage( (int) $golden['bonus_basis_points_max'] ) ); ?>%</bdi></span>
						<?php elseif ( null !== ( $golden['bonus_basis_points'] ?? null ) ) : ?>
							<span> · <bdi dir="ltr"><?php echo esc_html( $format_percentage( (int) $golden['bonus_basis_points'] ) ); ?>%</bdi></span>
						<?php endif; ?>
						<span> · پاداش برآوردی <bdi dir="ltr"><?php echo esc_html( $format_money( (int) $golden['bonus_minor'], (string) ( $golden['currency'] ?? $rate['currency'] ?? '' ) ) ); ?></bdi></span>
					<?php else : ?>
						<span> · سابقهٔ قابل اتکایی از پاداش ساعت طلایی برای این دوره‌ها در دسترس نیست.</span>
					<?php endif; ?>
				</li>
				<li>
					<strong>جمع درآمد مورد انتظار</strong>
					<?php if ( 'available' === (string) ( $expected['state'] ?? '' ) ) : ?>
						<span> · <bdi dir="ltr"><?php echo esc_html( $format_money( (int) $expected['amount_minor'], (string) $expected['currency'] ) ); ?></bdi> (شامل پاداش ثبت‌شده)</span>
					<?php else : ?>
						<span> · در دسترس نیست.</span>
					<?php endif; ?>
				</li>
			</ul>
		</section>
		<section class="dzn-tp-finance-terms" aria-labelledby="teacher-finance-terms">
			<h3 id="teacher-finance-terms">ترم‌های نزدیک به پایان</h3>
			<p class="dzn-tp-help">با هنرجو دربارهٔ پایان ترم صحبت کنید و تمدید را پیشنهاد دهید؛ با پرداخت هزینهٔ تمدید، ترم بعدی به‌صورت خودکار ثبت می‌شود.</p>
			<?php if ( ! $ending_terms ) : ?>
				<p class="dzn-tp-help">ترمی در سه جلسهٔ پایانی نیست.</p>
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
		<?php $payment_labels = array( 'paid' => 'پرداخت شده', 'unpaid' => 'در انتظار پرداخت' ); ?>
		<?php if ( 'queued' === sanitize_key( (string) ( $_GET['statement_email'] ?? '' ) ) ) : ?><p class="dzn-tp-action-status" role="status">صورت‌حساب برای ارسال به ایمیل شما در صف قرار گرفت.</p><?php elseif ( 'failed' === sanitize_key( (string) ( $_GET['statement_email'] ?? '' ) ) ) : ?><p class="dzn-tp-action-status" role="alert">ارسال ایمیل صورت‌حساب در حال حاضر ممکن نشد.</p><?php endif; ?>
		<ul class="dzn-tp-rule-list dzn-tp-statements">
			<?php foreach ( $statements as $statement ) : ?>
				<?php
				if ( ! in_array( (string) ( $statement['state'] ?? '' ), array( 'issued', 'paid', 'superseded' ), true ) ) { continue; }
				$currency = (string) ( $statement['currency'] ?? '' );
				$period   = (string) ( $statement['period_label'] ?? '' );
				if ( '' === $period ) {
					$period = trim( (string) ( $statement['period_start_utc'] ?? '' ) . ' – ' . (string) ( $statement['period_end_utc'] ?? '' ) );
				}
				$state_label = $states[ (string) ( $statement['state'] ?? '' ) ] ?? 'وضعیت نامشخص';
				$statementId = (int) ( $statement['statement_id'] ?? 0 );
				$payment_state = (string) ( $statement['payment_state'] ?? 'unpaid' );
				$payment_label = $payment_labels[ $payment_state ] ?? 'وضعیت پرداخت نامشخص';
				$paid_at = (string) ( $statement['paid_at'] ?? '' );
				$issued_at = (string) ( $statement['issued_at'] ?? '' );
				?>
				<li class="dzn-tp-statement">
					<p class="dzn-tp-statement__meta">
						<strong><?php echo esc_html( $period ); ?></strong>
						<span> · <?php echo esc_html( $payment_label ); ?><?php if ( 'paid' === $payment_state && '' !== $paid_at ) : ?> · <?php echo esc_html( $format_instant( $paid_at ) ); ?><?php endif; ?></span>
					</p>
					<p class="dzn-tp-statement__totals">
						<strong><?php echo esc_html( $format_money( (int) ( $statement['payable_amount_minor'] ?? 0 ), $currency ) ); ?></strong>
						<?php if ( (int) ( $statement['golden_hour_bonus_minor'] ?? 0 ) > 0 ) : ?>
							<span class="dzn-tp-golden-badge"><?php echo esc_html( sprintf( 'ساعت طلایی +%s', $format_money( (int) $statement['golden_hour_bonus_minor'], $currency ) ) ); ?></span>
						<?php endif; ?>
					</p>
					<div class="dzn-tp-statement__actions">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dzn-tp-statement__download">
							<input type="hidden" name="action" value="dzn_teacher_statement_download">
							<input type="hidden" name="presentation" value="print">
							<input type="hidden" name="statement_id" value="<?php echo (int) $statementId; ?>">
							<?php dzn_theme_nonce_field( 'dzn_teacher_statement_download' ); ?>
							<button class="button" type="submit">چاپ / دانلود PDF</button>
						</form>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="dzn_teacher_statement_email">
							<input type="hidden" name="statement_id" value="<?php echo (int) $statementId; ?>">
							<?php dzn_theme_nonce_field( 'dzn_teacher_statement_email' ); ?>
							<button class="button" type="submit">ایمیل به خودم</button>
						</form>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php elseif ( 'empty' === $state ) : ?>
		<p>هنوز صورت‌حسابی برای نمایش ثبت نشده است. پس از تکمیل کلاس‌ها و تایید پرداخت‌ها، صورت‌حساب ایجاد می‌شود.</p>
	<?php else : ?>
		<p>نمای مالی مدرس در حال حاضر در دسترس نیست. اگر نشست شما منقضی شده است، دوباره وارد شوید. مبلغ یا وضعیت پرداختی از روی کلاس‌ها حدس زده نمی‌شود.</p>
	<?php endif; ?>
</section>

<?php dzn_theme_component( 'tuition-allocation', array( 'model' => $m['tuition_allocations'] ?? array(), 'id' => 'teacher-tuition-allocation' ) ); ?>
