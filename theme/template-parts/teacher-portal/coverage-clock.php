<?php
/**
 * Teacher-facing 24-hour instrument coverage clock.
 *
 * Aggregate capacity only: no student identity, no other Teacher's name and no
 * availability of another Teacher is exposed. Clicking a segment only prefills
 * the existing availability form; nothing is saved by the clock.
 */
$m = is_array( $args['model'] ?? null ) ? $args['model'] : array();
$clock = is_array( $m['coverage_clock'] ?? null ) ? $m['coverage_clock'] : array();
$screen = (string) ( $m['screen'] ?? 'settings' );
$status = (string) ( $clock['status'] ?? 'unavailable' );
$mode = (string) ( $clock['mode'] ?? 'weekly' );
$calendar = (string) ( $clock['calendar'] ?? ( $m['profile']['calendar'] ?? 'gregorian' ) );
$timezone_label = (string) ( $clock['timezone_label'] ?? ( $m['profile']['timezone_label'] ?? '' ) );
$weekday = (int) ( $clock['weekday'] ?? 1 );
$duration = static function ( int $minutes ): string {
	$minutes = max( 0, $minutes );
	if ( $minutes === 0 ) { return '۰ دقیقه'; }
	$hours = intdiv( $minutes, 60 );
	$rest = $minutes % 60;
	return ( $hours > 0 ? $hours . ' ساعت ' : '' ) . ( $rest > 0 ? $rest . ' دقیقه' : '' );
};
$base = home_url( '/teacher-portal/' );
$link = static function ( array $changes ) use ( $base, $screen, $mode, $clock, $weekday ) {
	$args = array( 'teacher-view' => $screen, 'coverage_mode' => $mode, 'coverage_date' => (string) ( $clock['date'] ?? '' ), 'coverage_weekday' => $weekday );
	foreach ( $changes as $key => $value ) { if ( $value === null || $value === '' ) unset( $args[ $key ] ); else $args[ $key ] = $value; }
	return add_query_arg( $args, $base );
};
$selected = array();
foreach ( (array) ( $clock['instruments'] ?? array() ) as $instrument ) { if ( (int) $instrument['id'] === (int) ( $clock['selected_instrument'] ?? 0 ) ) { $selected = $instrument; break; } }
if ( ! $selected && ! empty( $clock['instruments'] ) ) { $selected = $clock['instruments'][0]; }
$metrics = (array) ( $selected['metrics'] ?? array() );
$has_clock = is_array( $selected['clock']['segments'] ?? null ) && count( (array) $selected['clock']['segments'] ) > 0;
$day_labels = array( 1 => 'دوشنبه', 2 => 'سه‌شنبه', 3 => 'چهارشنبه', 4 => 'پنجشنبه', 5 => 'جمعه', 6 => 'شنبه', 7 => 'یکشنبه' );
$labels = array(
	'covered' => 'پوشش کافی',
	'limited' => 'فقط یک مدرس',
	'uncovered' => 'ظرفیت باقی‌مانده نیست',
	'unknown' => 'ظرفیت نامشخص',
	'golden' => 'ساعت طلایی',
	'booked' => 'کاملاً رزرو شده',
	'past' => 'گذشته',
	'nonExistent' => 'این ساعت محلی در تغییر ساعت وجود ندارد',
	'noAvailability' => 'هیچ مدرس واجد شرایطی برای کل کلاس آزاد نیست',
	'teachers' => 'مدرس واجد شرایط',
	'teacher' => 'مدرس واجد شرایط',
	'select' => 'برای دیدن ظرفیت باقی‌مانده یک بخش را انتخاب کنید.',
	'selected' => 'انتخاب‌شده',
	'assumedLesson' => 'بر اساس کلاس کامل به‌همراه فاصله',
	'clock' => 'ساعت پوشش ۲۴ ساعته',
	'capacity' => 'ظرفیت باقی‌مانده',
	'declared' => 'دسترسی اعلام‌شده',
	'reason' => 'توضیح',
	'yes' => 'بله',
	'no' => 'خیر',
	'noWindow' => 'بدون بازه',
	'proposed' => 'پیشنهاد شما',
	'assumptionTemplate' => 'کلاس {lesson} دقیقه‌ای + {buffer} دقیقه فاصله',
);
?>
<section class="dzn-tp-section dzn-tp-clock dzn-clock" id="tp-coverage-clock" dir="rtl" aria-labelledby="tp-coverage-clock-title" data-dzn-clock-root data-dzn-clock-teacher>
	<div class="dzn-clock__head">
		<div class="dzn-clock__intro">
			<p class="dzn-clock__eyebrow">پوشش ساز</p>
			<h2 id="tp-coverage-clock-title">ساعت پوشش ۲۴ ساعته</h2>
			<p class="dzn-clock__meta">نیمه‌شب در بالای ساعت · حرکت در جهت عقربه‌های ساعت · بخش‌های ۱۵ دقیقه‌ای · <bdi><?php echo esc_html( $timezone_label ); ?></bdi></p>
		</div>
		<p class="dzn-clock__help"><?php echo esc_html( $mode === 'weekly' ? 'پوشش ساختاری هفتگی' : 'ظرفیت واقعی برای تاریخ انتخاب‌شده' ); ?><?php $selected_clock = is_array( $selected['clock'] ?? null ) ? $selected['clock'] : array(); $date_label_fa = (string) ( $selected_clock['date_label_fa'] ?? '' ); if ( '' !== $date_label_fa ) : ?> · <bdi><?php echo esc_html( $date_label_fa ); ?></bdi><?php endif; ?></p>
	</div>

	<?php if ( in_array( $status, array( 'unavailable', 'empty' ), true ) || ! $has_clock ) : ?>
		<div class="dzn-tp-empty-state" role="status"><h3>پوشش ساز در دسترس نیست</h3><p><?php echo esc_html( (string) ( $clock['message'] ?? ( ! $has_clock ? 'برای سازهای شما کلاس آشنایی فعالی ثبت نشده است، بنابراین ظرفیت قابل نمایش نیست.' : 'اطلاعات پوشش در حال حاضر خوانده نشد. زمان‌های تدریس شما تغییری نکرده است.' ) ) ); ?></p></div>
	<?php else : ?>
		<nav class="dzn-tp-clock__mode" aria-label="نوع نمایش پوشش">
			<a class="<?php echo $mode === 'weekly' ? 'is-active' : ''; ?>" href="<?php echo esc_url( $link( array( 'coverage_mode' => 'weekly' ) ) ); ?>"<?php echo $mode === 'weekly' ? ' aria-current="page"' : ''; ?>>پوشش هفتگی</a>
			<a class="<?php echo $mode === 'date' ? 'is-active' : ''; ?>" href="<?php echo esc_url( $link( array( 'coverage_mode' => 'date' ) ) ); ?>"<?php echo $mode === 'date' ? ' aria-current="page"' : ''; ?>>ظرفیت تاریخ مشخص</a>
		</nav>
		<?php if ( $mode === 'weekly' ) : ?>
			<div class="dzn-tp-clock__weekdays" role="group" aria-label="انتخاب روز هفته">
				<?php foreach ( $day_labels as $day => $label ) : ?>
					<a class="<?php echo $day === $weekday ? 'is-active' : ''; ?>" href="<?php echo esc_url( $link( array( 'coverage_mode' => 'weekly', 'coverage_weekday' => $day ) ) ); ?>"<?php echo $day === $weekday ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</div>
		<?php else : ?>
			<form method="get" action="<?php echo esc_url( $base ); ?>" class="dzn-tp-clock__date">
				<input type="hidden" name="teacher-view" value="<?php echo esc_attr( $screen ); ?>">
				<input type="hidden" name="coverage_mode" value="date">
				<label>تاریخ <input type="date" name="coverage_date" value="<?php echo esc_attr( (string) ( $clock['date'] ?? '' ) ); ?>"></label>
				<button class="dzn-button dzn-button--secondary" type="submit">نمایش</button>
			</form>
		<?php endif; ?>

		<p class="dzn-tp-clock__current" data-dzn-clock-current>ساز: <strong><?php echo esc_html( (string) ( $selected['name'] ?? '' ) ); ?></strong></p>

		<?php if ( count( (array) ( $clock['instruments'] ?? array() ) ) > 1 ) : ?>
			<div class="dzn-clock__instruments" role="list" aria-label="سازهای قابل تدریس">
				<?php foreach ( (array) $clock['instruments'] as $instrument ) : $is_selected = (int) $instrument['id'] === (int) $selected['id']; ?>
					<button type="button" class="dzn-clock__instrument<?php echo $is_selected ? ' is-selected' : ''; ?>" role="listitem" data-dzn-clock-instrument="<?php echo (int) $instrument['id']; ?>" aria-pressed="<?php echo $is_selected ? 'true' : 'false'; ?>">
						<span class="dzn-clock__instrument-name"><?php echo esc_html( (string) $instrument['name'] ); ?></span>
						<span class="dzn-clock__instrument-value"><?php echo esc_html( isset( $instrument['metrics']['coverage_percent'] ) && $instrument['metrics']['coverage_percent'] !== null ? (string) (int) $instrument['metrics']['coverage_percent'] . '%' : '—' ); ?></span>
					</button>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<div class="dzn-clock__metrics" role="list" aria-label="خلاصهٔ پوشش">
			<div class="dzn-clock__metric" role="listitem" data-dzn-metric="coverage"><span>پوشش</span><strong><?php echo esc_html( isset( $metrics['coverage_percent'] ) && $metrics['coverage_percent'] !== null ? (string) (int) $metrics['coverage_percent'] . '%' : '—' ); ?></strong><small>از بخش‌های اندازه‌گیری‌شده</small></div>
			<div class="dzn-clock__metric dzn-clock__metric--golden" role="listitem" data-dzn-metric="golden"><span>پوشش ساعت طلایی</span><strong><?php echo esc_html( isset( $metrics['golden_coverage_percent'] ) && $metrics['golden_coverage_percent'] !== null ? (string) (int) $metrics['golden_coverage_percent'] . '%' : 'بدون بازه' ); ?></strong><small>از بخش‌های ساعت طلایی</small></div>
			<div class="dzn-clock__metric" role="listitem" data-dzn-metric="uncovered"><span>بدون پوشش</span><strong><?php echo esc_html( $duration( (int) ( $metrics['uncovered_minutes'] ?? 0 ) ) ); ?></strong><small>زمان بدون ظرفیت</small></div>
			<div class="dzn-clock__metric" role="listitem" data-dzn-metric="single"><span>فقط یک مدرس</span><strong><?php echo esc_html( $duration( (int) ( $metrics['single_teacher_minutes'] ?? 0 ) ) ); ?></strong><small>وابستگی به یک مدرس</small></div>
			<div class="dzn-clock__metric" role="listitem" data-dzn-metric="teachers"><span>مدرسان واجد شرایط</span><strong><?php echo esc_html( (string) (int) ( $selected['eligible_teacher_count'] ?? 0 ) ); ?></strong><small>با دسترسی فعال</small></div>
			<div class="dzn-clock__metric dzn-clock__metric--golden-gap" role="listitem" data-dzn-metric="golden-gap"><span>حفرهٔ ساعت طلایی</span><strong><?php echo esc_html( $duration( (int) ( $metrics['golden_uncovered_minutes'] ?? 0 ) ) ); ?></strong><small>ساعت طلایی بدون پوشش</small></div>
		</div>

		<p class="dzn-clock__assumption">ظرفیت بر اساس <span data-dzn-clock-assumption><?php echo esc_html( str_replace( array( '{lesson}', '{buffer}' ), array( (int) ( $selected['lesson_minutes'] ?? 0 ), (int) ( $selected['buffer_minutes'] ?? 0 ) ), (string) $labels['assumptionTemplate'] ) ); ?></span> محاسبه می‌شود.</p>

		<div class="dzn-clock__stage">
			<div class="dzn-clock__clock-wrap"><div class="dzn-clock__canvas" data-dzn-clock-canvas role="group" aria-label="ساعت پوشش ۲۴ ساعته برای <?php echo esc_attr( (string) ( $selected['name'] ?? '' ) ); ?>"><noscript><p>برای رسم ساعت تعاملی، JavaScript لازم است. پوشش اندازه‌گیری‌شده در خلاصهٔ بالا آمده است.</p></noscript></div></div>
			<aside class="dzn-clock__details" data-dzn-clock-details aria-live="polite" aria-label="بخش انتخاب‌شده">
				<p class="dzn-clock__details-empty">برای دیدن ظرفیت باقی‌مانده یک بخش را انتخاب کنید. با کلیدهای جهت‌دار می‌توانید روی ساعت حرکت کنید.</p>
			</aside>
			<div class="dzn-clock__detail-actions">
				<button type="button" class="dzn-clock__use" data-dzn-clock-use disabled>استفاده از این زمان در فرم دسترسی</button>
				<p class="dzn-clock__proposal" data-dzn-clock-proposal aria-live="polite"></p>
			</div>
		</div>

		<div class="dzn-clock__legend" role="group" aria-label="راهنمای ساعت">
			<span><i class="dzn-clock__swatch dzn-clock__swatch--covered" aria-hidden="true"></i>پوشش کافی · دو مدرس یا بیشتر</span>
			<span><i class="dzn-clock__swatch dzn-clock__swatch--limited" aria-hidden="true"></i>فقط یک مدرس</span>
			<span><i class="dzn-clock__swatch dzn-clock__swatch--uncovered" aria-hidden="true"></i>ظرفیت باقی‌مانده نیست</span>
			<span><i class="dzn-clock__swatch dzn-clock__swatch--golden" aria-hidden="true"></i>ساعت طلایی · دارای پوشش</span>
			<span><i class="dzn-clock__swatch dzn-clock__swatch--golden-gap" aria-hidden="true"></i>ساعت طلایی · بدون ظرفیت</span>
			<span><i class="dzn-clock__swatch dzn-clock__swatch--booked" aria-hidden="true"></i>کاملاً رزرو شده</span>
		</div>

		<p class="dzn-tp-clock__note"><strong>ساعت طلایی چیست؟</strong> ساعت طلایی بازه‌ای است که تقاضای بیشتری برای آموزش وجود دارد. حلقهٔ طلایی بیرونی همیشه نمایش داده می‌شود، حتی وقتی هیچ مدرسی برای آن بازه آزاد نیست. رنگ طلایی به‌معنای تضمین پاداش نیست؛ مبلغ و واجد شرایط بودن پاداش فقط بر اساس قواعد مالی موجود و پس از برگزاری و احراز شرایط کلاس تعیین می‌شود. بخش‌های خاکستری یعنی هیچ مدرس واجد شرایطی نمی‌تواند یک کلاس کامل به‌همراه فاصلهٔ آن را در آن زمان بپذیرد.</p>
		<p class="dzn-clock__help">با کلیک روی یک بخش، فقط همان زمان در فرم زمان‌های تدریس شما انتخاب می‌شود؛ تا زمانی که خودتان فرم را ذخیره نکنید چیزی ثبت نمی‌شود. این نمایش فقط پوشش تجمیعی را نشان می‌دهد و نام یا برنامهٔ مدرسان دیگر را افشا نمی‌کند.</p>

		<script type="application/json" data-dzn-clock-data><?php echo \Delnavazan\Platform\Admin\View\InstrumentCoverageClockView::json( \Delnavazan\Platform\Admin\View\InstrumentCoverageClockView::dataPayload( $clock, $labels, false ) ); ?></script>
	<?php endif; ?>
</section>
