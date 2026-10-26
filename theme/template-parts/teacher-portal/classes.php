<?php
/**
 * Upcoming lessons. Presentation only: every action below uses a backend-issued URL, nonce and identifier from the view model.
 * Controls that exist only to preview a future design render solely in the non-production preview (see dzn_theme_teacher_portal_context()).
 */
$items    = is_array( $args['items'] ?? null ) ? $args['items'] : array();
$context  = dzn_theme_teacher_portal_context();
$demo     = ! empty( $context['demo'] );
$settings = (string) ( $context['settings_url'] ?? '' );
$timezone = (string) ( $args['timezone'] ?? ( $args['model']['teacher_timezone'] ?? '' ) );
?>
<section class="dzn-tp-section" aria-labelledby="tp-classes">
	<div class="dzn-tp-heading">
		<div><p class="dzn-tp-kicker">برنامهٔ پیش رو</p><h2 id="tp-classes">کلاس‌های پیش رو</h2></div>
		<div class="dzn-tp-heading__meta">
			<span class="dzn-tp-count-badge"><?php echo esc_html( $items ? sprintf( '%d کلاس · به ترتیب زمان', count( $items ) ) : 'به ترتیب زمان' ); ?></span>
			<?php if ( '' !== $timezone ) : ?><span class="dzn-timezone-label"><span aria-hidden="true">◷</span> منطقهٔ زمانی: <?php echo esc_html( $timezone ); ?></span><?php endif; ?>
		</div>
	</div>
<?php if ( ! $items ) : ?>
	<div class="dzn-tp-empty-state" role="status"><h3>کلاسی در برنامهٔ پیش رو ندارید</h3><p>کلاس‌های تأییدشده و زمان‌بندی‌شدهٔ شما همین‌جا نمایش داده می‌شوند. برای دریافت درخواست‌های تازه، زمان‌های در دسترس خود را به‌روز نگه دارید.</p><?php if ( '' !== $settings ) : ?><div class="dzn-tp-empty-state__actions"><a class="dzn-button dzn-button--secondary" href="<?php echo esc_url( $settings ); ?>">بررسی زمان‌های در دسترس</a></div><?php endif; ?></div>
<?php else : ?>
<ol class="dzn-tp-class-list">
<?php foreach ( $items as $index => $item ) :
	$valid = dzn_theme_teacher_portal_class_valid( $item );
	$state = $valid ? $item['state'] : 'unknown';
	$title = 'tp-class-title-' . $index;
	$help  = 'tp-join-' . $index;
	$date  = $valid && ! empty( $item['date'] ) ? (string) $item['date'] : '';
	if ( '' === $date && $valid && ! empty( $item['schedule_summary'] ) ) {
		$parts = explode( '،', (string) $item['schedule_summary'] );
		$date  = trim( (string) $parts[0] );
	}
	$starts_at = $valid ? (string) ( $item['starts_at_utc'] ?? '' ) : '';
	?>
<li><article class="dzn-tp-class is-<?php echo esc_attr( $state ); ?>" aria-labelledby="<?php echo esc_attr( $title ); ?>">
	<div class="dzn-tp-class__time-col">
		<time class="dzn-tp-class__time"<?php if ( '' !== $starts_at ) : ?> datetime="<?php echo esc_attr( $starts_at ); ?>"<?php endif; ?>>
			<span class="dzn-tp-time-clock"><span class="dzn-tp-sr">ساعت </span><?php echo esc_html( $valid ? $item['time'] : '—' ); ?></span>
			<?php if ( '' !== $date ) : ?><span class="dzn-tp-time-date"><?php echo esc_html( $date ); ?></span><?php endif; ?>
		</time>
	</div>
	<div class="dzn-tp-class__info-col">
		<div class="dzn-tp-class__title-row">
			<h3 id="<?php echo esc_attr( $title ); ?>" class="dzn-tp-class__student"><?php echo esc_html( $valid ? $item['student'] : 'کلاس در دسترس نیست' ); ?></h3>
			<?php if ( $valid ) : ?><p class="dzn-tp-state dzn-tp-state--<?php echo esc_attr( $state ); ?>"><span class="dzn-tp-state__bullet" aria-hidden="true">●</span> <span class="dzn-tp-sr">وضعیت: </span><?php echo esc_html( $item['status'] ); ?></p><?php endif; ?>
		</div>
		<?php if ( $valid ) : ?>
			<p class="dzn-tp-class__course-line"><span class="dzn-tp-class__course"><?php echo esc_html( $item['course'] ); ?></span> <span class="dzn-tp-class__dot" aria-hidden="true">·</span> <span class="dzn-tp-class__progress"><?php echo esc_html( $item['progress'] ); ?></span></p>
			<p class="dzn-tp-class__schedule-summary"><span aria-hidden="true">◷</span> <span class="dzn-tp-sr">زمان کلاس: </span><?php echo esc_html( $item['schedule_summary'] ); ?></p>
		<?php else : ?>
			<p class="dzn-tp-state">اطلاعات این کلاس کامل یا معتبر نیست؛ اقدام‌ها غیرفعال شدند. صفحه را دوباره بارگذاری کنید و اگر ادامه داشت به مدیر آموزشگاه خبر دهید.</p>
		<?php endif; ?>
	</div>
	<?php if ( $valid ) : ?>
	<div class="dzn-tp-actions">
		<?php if ( ! empty( $item['calendar_export_url'] ) ) : ?><a class="dzn-button dzn-button--secondary" href="<?php echo esc_url( (string) $item['calendar_export_url'] ); ?>">افزودن به تقویم</a><?php endif; ?>
		<?php if ( true === $item['start_available'] ) : ?>
			<form method="post" target="_blank" rel="noopener noreferrer" action="<?php echo esc_url( (string) ( $item['join_action_url'] ?? '' ) ); ?>" data-dzn-tp-submit data-dzn-tp-reenable="5000"><input type="hidden" name="_wpnonce" value="<?php echo esc_attr( (string) ( $item['join_nonce'] ?? '' ) ); ?>"><?php if ( ! empty( $item['confirmation_uid'] ) ) : ?><input type="hidden" name="confirmation_uid" value="<?php echo esc_attr( (string) $item['confirmation_uid'] ); ?>"><?php else : ?><input type="hidden" name="lesson_uid" value="<?php echo esc_attr( (string) ( $item['lesson_uid'] ?? '' ) ); ?>"><input type="hidden" name="schedule_version_uid" value="<?php echo esc_attr( (string) ( $item['schedule_version_uid'] ?? '' ) ); ?>"><?php endif; ?><button class="dzn-button dzn-tp-btn-join" type="submit" data-dzn-tp-busy-label="در حال باز کردن کلاس…"><span aria-hidden="true">▶</span> شروع کلاس</button></form>
		<?php else : ?>
			<button class="dzn-button dzn-tp-btn-join" type="button" disabled aria-describedby="<?php echo esc_attr( $help ); ?>"><span aria-hidden="true">▶</span> شروع کلاس</button>
		<?php endif; ?>
		<?php if ( true === $item['details_available'] ) : ?><button class="dzn-button dzn-button--secondary dzn-tp-btn-details" type="button" aria-expanded="false" aria-controls="tp-details-<?php echo esc_attr( $index ); ?>" data-dzn-tp-toggle="tp-details-<?php echo esc_attr( $index ); ?>" data-dzn-tp-label-open="بستن جزئیات" data-dzn-tp-label-closed="جزئیات">جزئیات</button><?php endif; ?>
	</div>
	<?php if ( true !== $item['start_available'] ) : ?>
		<p id="<?php echo esc_attr( $help ); ?>" class="dzn-tp-help dzn-tp-class__help"><?php echo esc_html( ! empty( $item['confirmation_uid'] ) && ! empty( $item['join_message'] ) ? (string) $item['join_message'] : 'شروع کلاس فقط زمانی فعال می‌شود که سامانه ورود امن را تأیید کرده و زمان ورود رسیده باشد.' ); ?></p>
	<?php endif; ?>
	<?php if ( true === $item['details_available'] ) : ?>
	<div id="tp-details-<?php echo esc_attr( $index ); ?>" class="dzn-tp-class-details" hidden>
		<div class="dzn-tp-detail-grid">
			<section class="dzn-tp-detail-card"><h4>خلاصهٔ هنرجو</h4><p><?php echo esc_html( $item['student_summary'] ); ?></p><h4>زمان‌بندی</h4><p><?php echo esc_html( $item['schedule_summary'] ); ?></p><h4>یادداشت خصوصی پیشین</h4><p><?php echo esc_html( $item['previous_private_note'] ); ?></p></section>
			<section class="dzn-tp-detail-card"><h4>گزارش برگزاری کلاس</h4><p class="dzn-tp-detail-help">گزارش شما شواهد بررسی است و به‌تنهایی وضعیت نهایی یا مالی کلاس را تعیین نمی‌کند. تکرار ارسال همان گزارش، رکورد تکراری نمی‌سازد.</p>
				<form method="post" action="<?php echo esc_url( (string) ( $item['delivery_recorded_action_url'] ?? '' ) ); ?>" data-dzn-tp-submit><input type="hidden" name="_wpnonce" value="<?php echo esc_attr( (string) ( $item['delivery_recorded_nonce'] ?? '' ) ); ?>"><input type="hidden" name="lesson_uid" value="<?php echo esc_attr( (string) ( $item['lesson_uid'] ?? '' ) ); ?>"><input type="hidden" name="schedule_version_uid" value="<?php echo esc_attr( (string) ( $item['schedule_version_uid'] ?? '' ) ); ?>"><button class="dzn-button" type="submit" data-dzn-tp-busy-label="در حال ثبت گزارش…">ثبت برگزاری کلاس</button></form>
				<form method="post" action="<?php echo esc_url( (string) ( $item['delivery_issue_action_url'] ?? '' ) ); ?>" data-dzn-tp-submit><input type="hidden" name="_wpnonce" value="<?php echo esc_attr( (string) ( $item['delivery_issue_nonce'] ?? '' ) ); ?>"><input type="hidden" name="lesson_uid" value="<?php echo esc_attr( (string) ( $item['lesson_uid'] ?? '' ) ); ?>"><input type="hidden" name="schedule_version_uid" value="<?php echo esc_attr( (string) ( $item['schedule_version_uid'] ?? '' ) ); ?>"><button class="dzn-button dzn-button--secondary" type="submit" data-dzn-tp-busy-label="در حال ثبت گزارش…">گزارش مشکل در برگزاری کلاس</button></form>
				<?php if ( $demo ) : ?><button type="button" data-dzn-tp-presentation>درخواست بررسی زمان</button><?php endif; ?></section>
		</div>
		<?php if ( $demo ) : ?>
		<section class="dzn-tp-private-note"><h4>یادداشت خصوصی مدرس</h4><p>فقط برای مدرس و تیم مجاز؛ برای هنرجو نمایش داده نمی‌شود.</p><textarea rows="3" aria-label="یادداشت خصوصی مدرس"></textarea></section>
		<section class="dzn-tp-practice"><h4>تمرین و تکلیف برای هنرجو</h4><p>پس از اتصال امن، این متن برای هنرجو قابل مشاهده خواهد بود.</p><textarea rows="3" aria-label="تمرین و تکلیف قابل مشاهده برای هنرجو"></textarea></section>
		<?php if ( 'replacement' === $item['state'] ) : ?><p class="dzn-tp-context">این جلسه در مدل نمایشی به‌عنوان جایگزین مجاز آمده است؛ پوسته استحقاق را ایجاد یا تعیین نمی‌کند.</p><?php endif; ?>
		<p class="dzn-tp-action-status" role="status" aria-live="polite"></p>
		<?php endif; ?>
	</div>
	<?php endif; ?>
	<?php endif; ?>
</article></li>
<?php endforeach; ?>
</ol>
<?php endif; ?>
</section>
