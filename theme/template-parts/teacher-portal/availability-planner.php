<?php
/**
 * Instrument-aware Teacher availability planner.
 *
 * One interactive 24-hour dial per weekday, a compact weekly overview and the
 * canonical Golden Hour / coverage-gap overlays. The dial and the native
 * 15-minute time inputs edit the same persisted schedule.
 *
 * Expected args:
 * - model The Teacher portal view model.
 */
$m = is_array( $args['model'] ?? null ) ? $args['model'] : array();
$planner = is_array( $m['availability_planner'] ?? null ) ? $m['availability_planner'] : array();
$profile = is_array( $m['profile'] ?? null ) ? $m['profile'] : array();
$timezone = (string) ( $profile['timezone'] ?? 'UTC' );
$days = array( 1 => 'دوشنبه', 2 => 'سه‌شنبه', 3 => 'چهارشنبه', 4 => 'پنجشنبه', 5 => 'جمعه', 6 => 'شنبه', 7 => 'یکشنبه' );
$week_order = array( 6, 7, 1, 2, 3, 4, 5 );
$instruments = is_array( $planner['instruments'] ?? null ) ? $planner['instruments'] : array();
$overview = is_array( $planner['overview'] ?? null ) ? $planner['overview'] : array();
$selected = (int) array_key_first( $instruments );
foreach ( $instruments as $instrument_id => $instrument ) {
	$has_ranges = false;
	foreach ( (array) ( $instrument['days'] ?? array() ) as $day_ranges ) {
		if ( ! empty( $day_ranges ) ) { $has_ranges = true; break; }
	}
	if ( $has_ranges ) { $selected = (int) $instrument_id; break; }
}
if ( empty( $instruments ) ) :
	?>
	<section class="dzn-tp-section dzn-ap" aria-labelledby="tp-availability">
		<div class="dzn-tp-heading"><div><p class="dzn-tp-kicker">برنامهٔ هفتگی</p><h2 id="tp-availability">برنامهٔ تدریس من</h2></div></div>
		<div class="dzn-tp-empty-state" role="status"><h3>ساز قابل تدریسی ثبت نشده است</h3><p>نخست در بخش «سازهای قابل تدریس» دست‌کم یک ساز را انتخاب کنید تا برنامهٔ هفتگی را تنظیم کنید.</p></div>
	</section>
	<?php
	return;
endif;
if ( empty( $planner['available'] ) ) :
	?>
	<section class="dzn-tp-section dzn-ap" aria-labelledby="tp-availability">
		<div class="dzn-tp-heading"><div><p class="dzn-tp-kicker">برنامهٔ هفتگی</p><h2 id="tp-availability">برنامهٔ تدریس من</h2></div></div>
		<p class="dzn-tp-notice is-warning" role="status"><?php echo esc_html( (string) ( $planner['message'] ?? 'برنامه‌ریز زمان تدریس در دسترس نیست.' ) ); ?></p>
	</section>
	<?php
	return;
endif;
$status_labels = array(
	'explicit' => 'زمان‌بندی‌شده',
	'empty' => 'بدون زمان',
	'inherit' => 'برنامهٔ عمومی',
);
$payload = array(
	'instruments' => $instruments,
	'golden' => (array) ( $planner['golden_bands'] ?? array() ),
	'gaps' => (array) ( $planner['gap_bands'] ?? array() ),
	'booked' => (array) ( $planner['booked_bands'] ?? array() ),
	'overview' => $overview,
	'labels' => array( 'days' => $days, 'statuses' => $status_labels ),
	'selected' => $selected,
	'timezone' => $timezone,
	'intake' => (int) ( $overview['student_intake_minutes'] ?? 0 ),
	'gap_available' => ! empty( $planner['gap_available'] ),
);
$save_url = admin_url( 'admin-post.php?action=dzn_teacher_availability_schedule' );
$empty_url = admin_url( 'admin-post.php?action=dzn_teacher_availability_instrument_empty' );
?>
<section class="dzn-tp-section dzn-ap" id="tp-availability" aria-labelledby="tp-availability-title" data-dzn-planner-root>
	<div class="dzn-tp-heading">
		<div>
			<p class="dzn-tp-kicker">برنامهٔ هفتگی</p>
			<h2 id="tp-availability-title">برنامهٔ تدریس من</h2>
		</div>
		<span class="dzn-ap__tz"><bdi><?php echo esc_html( (string) ( $planner['timezone_label'] ?? $timezone ) ); ?></bdi></span>
	</div>
	<p class="dzn-tp-help">روز را انتخاب کنید، سپس روی ساعت بکشید یا دو نقطه را بزنید. هر روز می‌تواند چند بازهٔ جدا داشته باشد. حلقهٔ داخلی کلاس‌های ثبت‌شدهٔ همین هفته را نشان می‌دهد؛ ساعت طلایی و کم‌پوشش‌ها هم همان‌جا مشخص‌اند تا بدانید کدام ساعت‌ها برای آکادمی مهم‌ترند.</p>

	<div class="dzn-ap__overview" role="group" aria-label="خلاصهٔ برنامهٔ هفتگی">
		<div><span>اختصاص‌یافته به دلنوازان</span><strong data-dzn-ap-metric="allocated"><?php echo esc_html( number_format_i18n( (int) ( $overview['allocated_minutes'] ?? 0 ) / 60, 1 ) ); ?></strong><small>ساعت در هفته</small></div>
		<div><span>در دسترس پس از هنرجو</span><strong data-dzn-ap-metric="available"><?php echo esc_html( number_format_i18n( (int) ( $overview['available_minutes'] ?? 0 ) / 60, 1 ) ); ?></strong><small>ساعت در هفته</small></div>
		<div><span>ساعت طلایی</span><strong data-dzn-ap-metric="golden"><?php echo esc_html( number_format_i18n( (int) ( $overview['golden_minutes'] ?? 0 ) / 60, 1 ) ); ?></strong><small>ساعت در هفته</small></div>
	</div>
	<p class="dzn-tp-help dzn-ap__overview-note">هنرجو: <bdi data-dzn-ap-intake><?php echo esc_html( number_format_i18n( (int) ( $overview['student_intake_minutes'] ?? 0 ) / 60, 1 ) ); ?></bdi> ساعت در این هفته. اعداد تقریبی‌اند و کلاس‌های ثبت‌شده را جابه‌جا نمی‌کنند.</p>

	<?php if ( count( $instruments ) > 1 ) : ?>
	<div class="dzn-ap__instrument-tabs" role="tablist" aria-label="سازها">
		<?php foreach ( $instruments as $instrument_id => $instrument ) : $is_active = (int) $instrument_id === $selected; ?>
		<button type="button" class="dzn-ap__instrument-tab<?php echo $is_active ? ' is-active' : ''; ?>" role="tab" aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>" data-dzn-ap-instrument="<?php echo (int) $instrument_id; ?>">
			<span><?php echo esc_html( (string) ( $instrument['name'] ?? '' ) ); ?></span>
			<small class="dzn-ap__status is-<?php echo esc_attr( (string) ( $instrument['status'] ?? 'inherit' ) ); ?>"><?php echo esc_html( $status_labels[ (string) ( $instrument['status'] ?? 'inherit' ) ] ?? '' ); ?></small>
		</button>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>

	<div class="dzn-ap__week" role="tablist" aria-label="روزهای هفته">
		<?php foreach ( $week_order as $weekday ) : $is_active = 6 === $weekday; ?>
		<button type="button" class="dzn-ap__day<?php echo $is_active ? ' is-active' : ''; ?>" role="tab" aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>" data-dzn-ap-day="<?php echo (int) $weekday; ?>">
			<span><?php echo esc_html( $days[ $weekday ] ); ?></span>
			<small data-dzn-ap-day-total="<?php echo (int) $weekday; ?>">—</small>
		</button>
		<?php endforeach; ?>
	</div>

	<div class="dzn-ap__workspace">
		<div class="dzn-ap__dial-wrap">
			<div class="dzn-ap__dial" data-dzn-ap-dial role="application" aria-label="ساعت انتخاب زمان تدریس. با کلیدهای جهت‌دار حرکت کنید و با Enter شروع یا پایان بازه را ثبت کنید." tabindex="0"></div>
			<div class="dzn-ap__legend" aria-label="راهنمای ساعت">
				<span class="is-selected"><i aria-hidden="true"></i>زمان شما</span>
				<span class="is-booked"><i aria-hidden="true"></i>کلاس ثبت‌شده</span>
				<span class="is-golden"><i aria-hidden="true"></i>ساعت طلایی</span>
				<span class="is-gap"><i aria-hidden="true"></i>کم‌پوشش</span>
			</div>
		</div>
		<div class="dzn-ap__day-panel">
			<div class="dzn-ap__day-head">
				<div><p class="dzn-tp-kicker">ویرایش روز</p><h3 data-dzn-ap-day-title>شنبه</h3></div>
				<span class="dzn-ap__unsaved" data-dzn-ap-unsaved hidden>ذخیره‌نشده</span>
			</div>
			<p class="dzn-tp-help dzn-ap__day-help">برای دقت بیشتر، از ورودی‌های ۱۵ دقیقه‌ای «از» و «تا» هم می‌توانید استفاده کنید.</p>
			<div class="dzn-ap__ranges" data-dzn-ap-ranges></div>
			<div class="dzn-ap__actions">
				<button type="button" class="dzn-button dzn-button--secondary dzn-button--small" data-dzn-ap-add>افزودن بازه</button>
				<button type="button" class="dzn-button dzn-button--secondary dzn-button--small" data-dzn-ap-clear-day>پاک کردن این روز</button>
			</div>
			<div class="dzn-ap__save-row">
				<form method="post" action="<?php echo esc_url( $save_url ); ?>" data-dzn-ap-form>
					<?php dzn_theme_nonce_field( 'dzn_teacher_availability_schedule' ); ?>
					<input type="hidden" name="instrument_id" value="<?php echo (int) $selected; ?>" data-dzn-ap-instrument-field>
					<input type="hidden" name="timezone" value="<?php echo esc_attr( $timezone ); ?>">
					<input type="hidden" name="ranges" value="[]" data-dzn-ap-ranges-field>
					<button type="submit" class="dzn-button" data-dzn-ap-save>ذخیرهٔ برنامه</button>
				</form>
				<form method="post" action="<?php echo esc_url( $empty_url ); ?>" data-dzn-ap-empty-form>
					<?php dzn_theme_nonce_field( 'dzn_teacher_availability_instrument_empty' ); ?>
					<input type="hidden" name="instrument_id" value="<?php echo (int) $selected; ?>" data-dzn-ap-empty-instrument-field>
					<button type="submit" class="dzn-button dzn-button--secondary" data-dzn-ap-empty>این ساز زمانی ندارد</button>
				</form>
			</div>
			<p class="dzn-tp-action-status" role="status" aria-live="polite" data-dzn-ap-status></p>
		</div>
	</div>

	<?php if ( empty( $planner['gap_available'] ) ) : ?>
	<p class="dzn-tp-help dzn-ap__gap-note">دادهٔ تقاضای آکادمی در حال حاضر در دسترس نیست؛ فقط ساعت طلایی نمایش داده می‌شود.</p>
	<?php endif; ?>

	<script type="application/json" data-dzn-planner-data><?php echo wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?></script>
</section>
