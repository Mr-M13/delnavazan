<?php
/**
 * One Teacher class row, shared by the intro, today, next-days and calendar sections.
 *
 * Presentation only: every action, URL, nonce and identifier comes from the view model. The
 * caller supplies a unique index and an id prefix so the same class can appear in two sections
 * (Today and the calendar) without duplicate element ids.
 *
 * Args: item (array), index (int), id_prefix (string), timezone (string), model (array), demo (bool).
 */
$item     = is_array( $args['item'] ?? null ) ? $args['item'] : array();
$index    = (int) ( $args['index'] ?? 0 );
$prefix   = trim( (string) ( $args['id_prefix'] ?? 'tp' ) );
if ( '' === $prefix ) { $prefix = 'tp'; }
$model    = is_array( $args['model'] ?? null ) ? $args['model'] : array();
$timezone = (string) ( $args['timezone'] ?? ( $model['teacher_timezone'] ?? '' ) );
$demo     = ! empty( $args['demo'] );
$valid    = dzn_theme_teacher_portal_class_valid( $item );
$is_today = ! empty( $item['is_today'] );
$state    = $valid ? $item['state'] : 'unknown';
$title    = $prefix . '-class-title-' . $index;
$details_id = $prefix . '-details-' . $index;
$date     = $valid && ! empty( $item['date'] ) ? (string) $item['date'] : '';
$starts_at = $valid ? (string) ( $item['starts_at_iso'] ?? '' ) : '';
$student_name = $valid ? trim( (string) ( $item['student'] ?? '' ) ) : '';
$student_tone = '' !== $student_name ? ( abs( crc32( $student_name ) ) % 6 ) + 1 : 0;
?>
<li data-dzn-tp-student="<?php echo esc_attr( $student_name ); ?>"><article class="dzn-tp-class is-<?php echo esc_attr( $state ); ?><?php echo $is_today ? '' : ' is-compact'; ?><?php echo $student_tone ? ' is-student-' . (int) $student_tone : ''; ?>" aria-labelledby="<?php echo esc_attr( $title ); ?>">
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
	<div class="dzn-tp-actions"<?php echo $is_today ? ' data-dzn-highlight="join"' : ''; ?>>
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
		<?php if ( function_exists( 'dzn_theme_recording_playback_button' ) ) { echo dzn_theme_recording_playback_button( $item, 'teacher' ); } ?>
		<?php if ( ! empty( $item['lesson_uid'] ) && ! empty( $item['schedule_version_uid'] ) && ( 'ended' === ( $item['temporal_state'] ?? '' ) || ! empty( $item['completion_available'] ) ) ) : ?>
			<?php $finish_url = add_query_arg( array( 'lesson_uid' => (string) $item['lesson_uid'], 'schedule_version_uid' => (string) $item['schedule_version_uid'], 'view' => 'finish' ), home_url( '/music-classroom/' ) ); ?>
			<a class="dzn-button dzn-button--secondary" href="<?php echo esc_url( $finish_url ); ?>">ثبت یادداشت و پایان کلاس</a>
		<?php endif; ?>
		<?php if ( true === $item['details_available'] ) : ?><button class="dzn-tp-chevron" type="button" aria-expanded="false" aria-controls="<?php echo esc_attr( $details_id ); ?>" aria-label="نمایش جزئیات کلاس" data-dzn-tp-toggle="<?php echo esc_attr( $details_id ); ?>" data-dzn-tp-label-open="بستن جزئیات کلاس" data-dzn-tp-label-closed="نمایش جزئیات کلاس"><span class="dzn-tp-chevron__icon" aria-hidden="true">⌄</span></button><?php endif; ?>
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
			<section class="dzn-tp-detail-card" data-dzn-highlight="attendance"><h4>تأیید برگزاری و حضور</h4>
				<?php if ( true === ( $item['completion_available'] ?? false ) ) : ?>
					<p class="dzn-tp-detail-help">پس از پایان کلاس، اگر جلسه برگزار شد برگزاری و حضور را تأیید کنید.</p>
					<form method="post" action="<?php echo esc_url( (string) ( $item['completion_action_url'] ?? $item['delivery_recorded_action_url'] ?? '' ) ); ?>" data-dzn-tp-submit><input type="hidden" name="_wpnonce" value="<?php echo esc_attr( (string) ( $item['completion_nonce'] ?? $item['delivery_recorded_nonce'] ?? '' ) ); ?>"><input type="hidden" name="lesson_uid" value="<?php echo esc_attr( (string) ( $item['lesson_uid'] ?? '' ) ); ?>"><input type="hidden" name="schedule_version_uid" value="<?php echo esc_attr( (string) ( $item['schedule_version_uid'] ?? '' ) ); ?>"><button class="dzn-button" type="submit" data-dzn-tp-busy-label="در حال ثبت گزارش…">تأیید برگزاری و حضور</button></form>
				<?php else : ?>
					<p class="dzn-tp-detail-help"><?php echo esc_html( (string) ( $item['completion_message'] ?? 'این کلاس در وضعیت قابل گزارش نیست.' ) ); ?></p>
				<?php endif; ?>
				<?php
				$attendance_evidence = isset( $item['attendance_evidence'] ) && is_array( $item['attendance_evidence'] ) ? $item['attendance_evidence'] : array();
				if ( ! empty( $attendance_evidence['state'] ) ) {
					$evidence_minutes = (int) ( $attendance_evidence['minutes'] ?? 0 );
					$evidence_threshold = (int) ( $attendance_evidence['threshold_minutes'] ?? 20 );
					$evidence_reasons = array(
						'overlap_confirmed'       => 'هم‌زمانی کافی مدرس و هنرجو ثبت شد.',
						'overlap_below_threshold' => 'هم‌زمانی مدرس و هنرجو کمتر از حد لازم بود.',
						'identity_unmapped'       => 'حساب Google هنوز به این کلاس نگاشته نشده است.',
						'meet_evidence_missing'   => 'شواهد حضور در Meet پیدا نشد.',
						'sessions_unavailable'    => 'شواهد حضور در دسترس نیست.',
					);
					$evidence_reason = (string) ( $evidence_reasons[ (string) ( $attendance_evidence['reason'] ?? '' ) ] ?? 'داده کافی برای تأیید حضور نیست.' );
					$evidence_state = (string) $attendance_evidence['state'] === 'confirmed'
						? 'تأیید شد'
						: ( (string) $attendance_evidence['state'] === 'pending' ? 'تأیید نشد' : 'در دسترس نیست' );
				?>
					<p class="dzn-tp-detail-help" data-dzn-attendance-evidence><?php echo esc_html( 'حضور خودکار Meet: ' . $evidence_state . ' — ' . dzn_theme_platform_persian_digits( $evidence_minutes ) . ' از ' . dzn_theme_platform_persian_digits( $evidence_threshold ) . ' دقیقه هم‌زمانی. ' . $evidence_reason ); ?></p>
				<?php } ?>
				<?php if ( $demo ) : ?><button type="button" data-dzn-tp-presentation>درخواست بررسی زمان</button><?php endif; ?></section>
		</div>
		<?php if ( $is_today && true === ( $item['reschedule_available'] ?? false ) ) {
			dzn_theme_teacher_portal_component(
				'reschedule-form',
				array( 'item' => $item, 'timezone' => $timezone, 'index' => $index, 'demo' => $demo, 'calendar' => (string) ( $model['teacher_calendar'] ?? $item['reschedule_calendar'] ?? '' ), 'locale' => (string) ( $model['teacher_locale'] ?? $item['reschedule_locale'] ?? '' ) )
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
	<?php if ( ! empty( $item['intro_change_available'] ) ) : ?>
		<?php dzn_theme_teacher_portal_component( 'intro-actions', array( 'item' => $item, 'timezone' => $timezone, 'calendar' => (string) ( $model['teacher_calendar'] ?? $item['intro_calendar'] ?? $item['reschedule_calendar'] ?? '' ), 'locale' => (string) ( $model['teacher_locale'] ?? $item['intro_locale'] ?? $item['reschedule_locale'] ?? '' ) ) ); ?>
	<?php endif; ?>
	<?php if ( ! $is_today && true === ( $item['reschedule_available'] ?? false ) ) : ?>
	<details class="dzn-tp-class__reschedule" data-dzn-highlight="reschedule">
		<summary>تغییر زمان کلاس</summary>
		<?php dzn_theme_teacher_portal_component( 'reschedule-form', array( 'item' => $item, 'timezone' => $timezone, 'index' => $index, 'demo' => $demo, 'calendar' => (string) ( $model['teacher_calendar'] ?? $item['reschedule_calendar'] ?? '' ), 'locale' => (string) ( $model['teacher_locale'] ?? $item['reschedule_locale'] ?? '' ) ) ); ?>
	</details>
	<?php endif; ?>
	<?php endif; ?>
</article></li>
