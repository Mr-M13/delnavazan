<?php
/**
 * Upcoming lessons. Presentation only: every action below uses a backend-issued URL, nonce and identifier from the view model.
 * Controls that exist only to preview a future design render solely in the non-production preview (see dzn_theme_teacher_portal_context()).
 */
$items    = is_array( $args['items'] ?? null ) ? $args['items'] : array();
$context  = dzn_theme_teacher_portal_context();
$demo     = ! empty( $context['demo'] );
$settings = (string) ( $context['settings_url'] ?? '' );
?>
<section class="dzn-tp-section" aria-labelledby="tp-classes"><div class="dzn-tp-heading"><div><p class="dzn-tp-kicker">برنامهٔ پیش رو</p><h2 id="tp-classes">کلاس‌های پیش رو</h2></div><span><?php echo esc_html( $items ? sprintf( '%d کلاس · به ترتیب زمان', count( $items ) ) : 'به ترتیب زمان' ); ?></span></div>
<?php if ( ! $items ) : ?>
	<div class="dzn-tp-empty" role="status"><h3>کلاسی در برنامهٔ پیش رو ندارید</h3><p>کلاس‌های تأییدشده و زمان‌بندی‌شدهٔ شما همین‌جا نمایش داده می‌شوند. برای دریافت درخواست‌های تازه، زمان‌های در دسترس خود را به‌روز نگه دارید.</p><?php if ( '' !== $settings ) : ?><a class="dzn-button dzn-button--secondary" href="<?php echo esc_url( $settings ); ?>">بررسی زمان‌های در دسترس</a><?php endif; ?></div>
<?php else : ?>
<ol class="dzn-tp-class-list">
<?php foreach ( $items as $index => $item ) :
	$valid = dzn_theme_teacher_portal_class_valid( $item );
	$state = $valid ? $item['state'] : 'unknown';
	$title = 'tp-class-title-' . $index;
	$help  = 'tp-join-' . $index;
	?>
<li><article class="dzn-tp-class is-<?php echo esc_attr( $state ); ?>" aria-labelledby="<?php echo esc_attr( $title ); ?>">
	<p class="dzn-tp-class__time"><?php if ( $valid ) : ?><span class="dzn-tp-sr">ساعت </span><?php echo esc_html( $item['time'] ); ?><?php else : ?>—<?php endif; ?></p>
	<div class="dzn-tp-class__body">
		<h3 id="<?php echo esc_attr( $title ); ?>"><?php echo esc_html( $valid ? $item['student'] : 'کلاس در دسترس نیست' ); ?></h3>
		<?php if ( $valid ) : ?>
			<p class="dzn-tp-class__course"><?php echo esc_html( $item['course'] ); ?> · <?php echo esc_html( $item['progress'] ); ?></p>
			<p class="dzn-tp-class__schedule"><span class="dzn-tp-sr">زمان کلاس: </span><?php echo esc_html( $item['schedule_summary'] ); ?></p>
			<p class="dzn-tp-state"><span aria-hidden="true">●</span> <span class="dzn-tp-sr">وضعیت: </span><?php echo esc_html( $item['status'] ); ?></p>
		<?php else : ?>
			<p class="dzn-tp-state">اطلاعات این کلاس کامل یا معتبر نیست؛ اقدام‌ها غیرفعال شدند. صفحه را دوباره بارگذاری کنید و اگر ادامه داشت به مدیر آموزشگاه خبر دهید.</p>
		<?php endif; ?>
	</div>
	<?php if ( $valid ) : ?>
	<div class="dzn-tp-actions">
		<?php if ( true === $item['start_available'] ) : ?>
			<form method="post" target="_blank" rel="noopener noreferrer" action="<?php echo esc_url( (string) ( $item['join_action_url'] ?? '' ) ); ?>" data-dzn-tp-submit data-dzn-tp-reenable="5000"><input type="hidden" name="_wpnonce" value="<?php echo esc_attr( (string) ( $item['join_nonce'] ?? '' ) ); ?>"><?php if ( ! empty( $item['confirmation_uid'] ) ) : ?><input type="hidden" name="confirmation_uid" value="<?php echo esc_attr( (string) $item['confirmation_uid'] ); ?>"><?php else : ?><input type="hidden" name="lesson_uid" value="<?php echo esc_attr( (string) ( $item['lesson_uid'] ?? '' ) ); ?>"><input type="hidden" name="schedule_version_uid" value="<?php echo esc_attr( (string) ( $item['schedule_version_uid'] ?? '' ) ); ?>"><?php endif; ?><button class="dzn-button" type="submit" data-dzn-tp-busy-label="در حال باز کردن کلاس…">شروع کلاس</button></form>
		<?php else : ?>
			<button class="dzn-button" type="button" disabled aria-describedby="<?php echo esc_attr( $help ); ?>">شروع کلاس</button>
		<?php endif; ?>
		<?php if ( true === $item['details_available'] ) : ?><button class="dzn-button dzn-button--secondary" type="button" aria-expanded="false" aria-controls="tp-details-<?php echo esc_attr( $index ); ?>" data-dzn-tp-toggle="tp-details-<?php echo esc_attr( $index ); ?>" data-dzn-tp-label-open="بستن جزئیات" data-dzn-tp-label-closed="جزئیات">جزئیات</button><?php endif; ?>
	</div>
	<?php if ( true !== $item['start_available'] ) : ?>
		<p id="<?php echo esc_attr( $help ); ?>" class="dzn-tp-help dzn-tp-class__help"><?php echo esc_html( ! empty( $item['confirmation_uid'] ) && ! empty( $item['join_message'] ) ? (string) $item['join_message'] : 'شروع کلاس فقط زمانی فعال می‌شود که سامانه ورود امن را تأیید کرده و زمان ورود رسیده باشد.' ); ?></p>
	<?php endif; ?>
	<?php if ( true === $item['details_available'] ) : ?>
	<div id="tp-details-<?php echo esc_attr( $index ); ?>" class="dzn-tp-class-details" hidden>
		<div class="dzn-tp-detail-grid">
			<section><h4>خلاصهٔ هنرجو</h4><p><?php echo esc_html( $item['student_summary'] ); ?></p><h4>زمان‌بندی</h4><p><?php echo esc_html( $item['schedule_summary'] ); ?></p><h4>یادداشت خصوصی پیشین</h4><p><?php echo esc_html( $item['previous_private_note'] ); ?></p></section>
			<section><h4>مشکل در برگزاری کلاس</h4><p>اگر در برگزاری این کلاس مشکلی رخ داد، آن را از همین‌جا گزارش دهید تا بررسی مدیر روی رکورد درست انجام شود.</p>
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
<?php endif; ?></section>
