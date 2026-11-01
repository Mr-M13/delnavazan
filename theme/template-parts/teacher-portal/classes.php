<?php
/**
 * Today, the completed/past history and the next 30 local calendar days.
 *
 * Presentation only: every action below uses a backend-issued URL, nonce and identifier from the
 * view model. The classes section is bounded to the teacher's current local day; the calendar is
 * rendered by the separate calendar component.
 */
$model    = is_array( $args['model'] ?? null ) ? $args['model'] : array();
$context  = dzn_theme_teacher_portal_context();
$demo     = ! empty( $context['demo'] );
$settings = (string) ( $context['settings_url'] ?? '' );
$timezone = (string) ( $args['timezone'] ?? ( $model['teacher_timezone'] ?? $model['profile']['timezone'] ?? '' ) );
$today_items = isset( $args['today_items'] ) && is_array( $args['today_items'] ) ? $args['today_items'] : ( isset( $model['today_classes'] ) && is_array( $model['today_classes'] ) ? $model['today_classes'] : array() );
$past_items  = isset( $args['past_items'] ) && is_array( $args['past_items'] ) ? $args['past_items'] : ( isset( $model['past_classes'] ) && is_array( $model['past_classes'] ) ? $model['past_classes'] : array() );
$sections = array(
	array( 'key' => 'today', 'heading' => 'کلاس‌های امروز', 'visible_heading' => true, 'items' => $today_items, 'empty_title' => 'کلاسی برای امروز ثبت نشده است' ),
);
$section_heading = 'کلاس‌های امروز';
$card_index = 0;
?>
<section class="dzn-tp-section dzn-tp-classes" aria-labelledby="tp-classes">
	<div class="dzn-tp-heading">
		<h2 id="tp-classes"><?php echo esc_html( $section_heading ); ?></h2>
		<?php if ( '' !== $timezone ) : ?><span class="dzn-timezone-label"><span aria-hidden="true">◷</span> <?php echo esc_html( $timezone ); ?></span><?php endif; ?>
	</div>
	<p class="dzn-tp-help dzn-tp-classes__guidance">برای دیدن جزئیات یا تغییر زمان، فلش کنار هر کلاس را باز کنید.</p>
<?php foreach ( $sections as $section ) : ?>
	<section class="dzn-tp-class-section dzn-tp-class-section--<?php echo esc_attr( $section['key'] ); ?>" aria-labelledby="tp-classes-<?php echo esc_attr( $section['key'] ); ?>">
		<h3 id="tp-classes-<?php echo esc_attr( $section['key'] ); ?>" class="<?php echo $section['visible_heading'] ? 'dzn-tp-class-section__title' : 'dzn-tp-sr'; ?>"><?php echo esc_html( $section['heading'] ); ?></h3>
	<?php if ( ! $section['items'] ) : ?>
		<div class="dzn-tp-empty-state dzn-tp-empty-state--compact" role="status"><h3><?php echo esc_html( $section['empty_title'] ); ?></h3><?php if ( '' !== $settings ) : ?><div class="dzn-tp-empty-state__actions"><a class="dzn-button dzn-button--secondary" href="<?php echo esc_url( $settings ); ?>">بررسی زمان‌های در دسترس</a></div><?php endif; ?></div>
	<?php else : ?>
		<ol class="dzn-tp-class-list">
		<?php foreach ( $section['items'] as $item ) :
			$index = $card_index++;
			$valid = dzn_theme_teacher_portal_class_valid( $item );
			$is_today = ! empty( $item['is_today'] );
			$state = $valid ? $item['state'] : 'unknown';
			$title = 'tp-class-title-' . $index;
			$help  = 'tp-join-' . $index;
			$details_id = 'tp-details-' . $index;
			$date  = $valid && ! empty( $item['date'] ) ? (string) $item['date'] : '';
			$starts_at = $valid ? (string) ( $item['starts_at_iso'] ?? '' ) : '';
			$join_reason = ! empty( $item['confirmation_uid'] ) && ! empty( $item['join_message'] ) ? (string) $item['join_message'] : 'زمان ورود نرسیده یا دسترسی امن برای این کلاس تأیید نشده است.';
			?>
		<li><article class="dzn-tp-class is-<?php echo esc_attr( $state ); ?><?php echo $is_today ? '' : ' is-compact'; ?>" aria-labelledby="<?php echo esc_attr( $title ); ?>">
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
					<?php
					$golden_label = (string) ( $item['golden_hour_badge'] ?? $item['golden_hour_label'] ?? '' );
					$golden_review = (string) ( $item['golden_hour_review_text'] ?? '' );
					?>
					<?php if ( $valid && '' !== $golden_label ) : ?><span class="dzn-tp-golden-badge" title="<?php echo esc_attr( (string) ( $item['golden_hour_estimate_label'] ?? '' ) === 'estimated' ? 'برآورد تا پیش از قطعی‌شدن پرداخت' : 'ثبت‌شده در محاسبهٔ نهایی' ); ?>"><?php echo esc_html( $golden_label ); ?></span><?php endif; ?>
					<?php if ( $valid && '' !== $golden_review ) : ?><span class="dzn-tp-golden-review"><?php echo esc_html( $golden_review ); ?></span><?php endif; ?>
				</div>
				<?php if ( $valid ) : ?>
					<p class="dzn-tp-class__course-line"><span class="dzn-tp-class__course"><?php echo esc_html( $item['course'] ); ?></span><?php if ( $is_today ) : ?> <span class="dzn-tp-class__dot" aria-hidden="true">·</span> <span class="dzn-tp-class__progress"><?php echo esc_html( $item['progress'] ); ?></span><?php endif; ?></p>
					<p class="dzn-tp-class__schedule-summary"><span aria-hidden="true">◷</span> <span class="dzn-tp-sr">زمان کلاس: </span><?php echo esc_html( $item['schedule_summary'] ); ?></p>
				<?php else : ?>
					<p class="dzn-tp-state">اطلاعات این کلاس کامل یا معتبر نیست؛ اقدام‌ها غیرفعال شدند. صفحه را دوباره بارگذاری کنید و اگر ادامه داشت به مدیر آموزشگاه خبر دهید.</p>
				<?php endif; ?>
			</div>
			<?php if ( $valid ) : ?>
			<div class="dzn-tp-actions">
				<?php if ( $is_today ) : ?>
					<?php
					get_template_part(
						'template-parts/components/classroom-actions',
						null,
						array(
							'item' => $item,
							'role' => 'teacher',
							'primary_class' => 'dzn-button dzn-tp-btn-join',
							'meet_label' => 'ورود با گوگل میت (پشتیبان)',
						)
					);
					?>
				<?php endif; ?>
				<?php if ( true === $item['details_available'] ) : ?><button class="dzn-tp-chevron" type="button" aria-expanded="false" aria-controls="<?php echo esc_attr( $details_id ); ?>" aria-label="نمایش جزئیات کلاس" data-dzn-tp-toggle="<?php echo esc_attr( $details_id ); ?>" data-dzn-tp-label-open="بستن جزئیات کلاس" data-dzn-tp-label-closed="نمایش جزئیات کلاس"><span class="dzn-tp-chevron__icon" aria-hidden="true">⌄</span></button><?php endif; ?>
				<?php if ( ! empty( $item['calendar_export_url'] ) ) : ?><a class="dzn-tp-calendar-action" href="<?php echo esc_url( (string) $item['calendar_export_url'] ); ?>" title="افزودن به تقویم" aria-label="افزودن این کلاس به تقویم"><span aria-hidden="true">＋</span> تقویم</a><?php endif; ?>
			</div>
			<?php if ( true === $item['details_available'] ) : ?>
			<div id="<?php echo esc_attr( $details_id ); ?>" class="dzn-tp-class-details" hidden>
				<div class="dzn-tp-detail-grid">
					<section class="dzn-tp-detail-card"><h4>تماس با هنرجو</h4>
						<?php $whatsapp = preg_replace( '/[^0-9]/', '', (string) ( $item['student_whatsapp'] ?? '' ) ); ?>
						<p class="dzn-tp-student-contact"><span>واتس‌اپ:</span> <?php if ( '' !== $whatsapp ) : ?><a dir="ltr" href="<?php echo esc_url( 'https://wa.me/' . $whatsapp ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( (string) $item['student_whatsapp'] ); ?></a><?php else : ?><span class="dzn-tp-muted">ثبت نشده</span><?php endif; ?></p>
						<?php if ( '' !== (string) ( $item['golden_hour_label'] ?? '' ) ) : ?>
							<p class="dzn-tp-class__golden"><?php echo esc_html( (string) $item['golden_hour_label'] ); ?></p>
							<?php if ( '' !== (string) ( $item['golden_hour_window_display'] ?? '' ) ) : ?><p class="dzn-tp-help">بازهٔ محلی: <?php echo esc_html( (string) $item['golden_hour_window_display'] ); ?></p><?php endif; ?>
						<?php elseif ( '' !== (string) ( $item['golden_hour_review_text'] ?? '' ) ) : ?>
							<p class="dzn-tp-help"><?php echo esc_html( (string) $item['golden_hour_review_text'] ); ?></p>
						<?php endif; ?>
						<?php if ( '' !== (string) ( $item['term_progress_label'] ?? '' ) ) : ?><p><strong>پیشرفت ترم:</strong> <?php echo esc_html( (string) $item['term_progress_label'] ); ?><?php if ( '' !== (string) ( $item['replacement_label'] ?? '' ) ) : ?> · <?php echo esc_html( (string) $item['replacement_label'] ); ?><?php endif; ?></p><?php endif; ?>
						<?php if ( isset( $item['previous_private_note'] ) && '' !== trim( (string) $item['previous_private_note'] ) ) : ?><h4>یادداشت خصوصی پیشین</h4><p><?php echo esc_html( (string) $item['previous_private_note'] ); ?></p><?php endif; ?></section>
					<section class="dzn-tp-detail-card"><h4>تأیید برگزاری و حضور</h4>
						<?php if ( true === ( $item['completion_available'] ?? false ) ) : ?>
							<p class="dzn-tp-detail-help">پس از پایان کلاس، اگر جلسه برگزار شد برگزاری و حضور را تأیید کنید.</p>
							<form method="post" action="<?php echo esc_url( (string) ( $item['completion_action_url'] ?? $item['delivery_recorded_action_url'] ?? '' ) ); ?>" data-dzn-tp-submit><input type="hidden" name="_wpnonce" value="<?php echo esc_attr( (string) ( $item['completion_nonce'] ?? $item['delivery_recorded_nonce'] ?? '' ) ); ?>"><input type="hidden" name="lesson_uid" value="<?php echo esc_attr( (string) ( $item['lesson_uid'] ?? '' ) ); ?>"><input type="hidden" name="schedule_version_uid" value="<?php echo esc_attr( (string) ( $item['schedule_version_uid'] ?? '' ) ); ?>"><button class="dzn-button" type="submit" data-dzn-tp-busy-label="در حال ثبت گزارش…">تأیید برگزاری و حضور</button></form>
						<?php else : ?>
							<p class="dzn-tp-detail-help"><?php echo esc_html( (string) ( $item['completion_message'] ?? 'این کلاس در وضعیت قابل گزارش نیست.' ) ); ?></p>
						<?php endif; ?>
						<?php if ( $demo ) : ?><button type="button" data-dzn-tp-presentation>درخواست بررسی زمان</button><?php endif; ?></section>
				</div>
				<?php if ( $is_today && true === ( $item['reschedule_available'] ?? false ) ) {
					dzn_theme_teacher_portal_component(
						'reschedule-form',
						array( 'item' => $item, 'timezone' => $timezone, 'index' => $index, 'demo' => $demo )
					);
				} ?>
				<?php if ( $demo ) : ?>
				<section class="dzn-tp-private-note"><h4>یادداشت خصوصی مدرس</h4><p>فقط برای مدرس و تیم مجاز؛ برای هنرجو نمایش داده نمی‌شود.</p><textarea rows="3" aria-label="یادداشت خصوصی مدرس"></textarea></section>
				<section class="dzn-tp-practice"><h4>تمرین و تکلیف برای هنرجو</h4><p>پس از اتصال امن، این متن برای هنرجو قابل مشاهده خواهد بود.</p><textarea rows="3" aria-label="تمرین و تکلیف قابل مشاهده برای هنرجو"></textarea></section>
				<?php if ( 'replacement' === $item['state'] ) : ?><p class="dzn-tp-context">این جلسه در مدل نمایشی به‌عنوان جایگزین مجاز آمده است؛ پوسته استحقاق را ایجاد یا تعیین نمی‌کند.</p><?php endif; ?>
				<p class="dzn-tp-action-status" role="status" aria-live="polite"></p>
				<?php endif; ?>
			</div>
			<?php endif; ?>
			<?php if ( ! $is_today && true === ( $item['reschedule_available'] ?? false ) ) : ?>
			<details class="dzn-tp-class__reschedule">
				<summary>تغییر زمان کلاس</summary>
				<?php dzn_theme_teacher_portal_component( 'reschedule-form', array( 'item' => $item, 'timezone' => $timezone, 'index' => $index, 'demo' => $demo ) ); ?>
			</details>
			<?php endif; ?>
			<?php endif; ?>
		</article></li>
		<?php endforeach; ?>
		</ol>
	<?php endif; ?>
	</section>
<?php endforeach; ?>
	<?php if ( $past_items ) : ?>
	<details class="dzn-tp-class-history">
		<summary>کلاس‌های گذشته <span class="dzn-tp-class-history__count"><?php echo esc_html( (string) count( $past_items ) ); ?></span></summary>
		<ol class="dzn-tp-class-history__list">
			<?php foreach ( $past_items as $past ) : ?>
				<?php
				$past_valid = is_array( $past )
					&& '' !== trim( (string) ( $past['ref'] ?? '' ) )
					&& '' !== trim( (string) ( $past['date'] ?? '' ) )
					&& '' !== trim( (string) ( $past['time'] ?? '' ) )
					&& '' !== trim( (string) ( $past['student'] ?? '' ) )
					&& '' !== trim( (string) ( $past['outcome'] ?? '' ) );
				if ( ! $past_valid ) { continue; }
				?>
				<li class="dzn-tp-class-history__item is-<?php echo esc_attr( (string) ( $past['tone'] ?? 'muted' ) ); ?>">
					<p class="dzn-tp-class-history__when"><time><?php echo esc_html( (string) $past['date'] ); ?> · <?php echo esc_html( (string) $past['time'] ); ?></time></p>
					<p class="dzn-tp-class-history__who"><strong><?php echo esc_html( (string) $past['student'] ); ?></strong><?php if ( '' !== trim( (string) ( $past['course'] ?? '' ) ) ) : ?> · <?php echo esc_html( (string) $past['course'] ); ?><?php endif; ?><?php if ( '' !== trim( (string) ( $past['term_reference'] ?? '' ) ) ) : ?> · ترم <?php echo esc_html( (string) $past['term_reference'] ); ?><?php endif; ?></p>
					<p class="dzn-tp-class-history__outcome"><?php echo esc_html( (string) $past['outcome'] ); ?></p>
					<?php if ( '' !== trim( (string) ( $past['term_progress_label'] ?? '' ) ) ) : ?><p class="dzn-tp-help"><?php echo esc_html( (string) $past['term_progress_label'] ); ?></p><?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ol>
	</details>
	<?php endif; ?>
</section>
