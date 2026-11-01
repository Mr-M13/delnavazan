<?php
$items    = is_array( $args['items'] ?? null ) ? $args['items'] : array();
$context  = dzn_theme_teacher_portal_context();
$demo     = ! empty( $context['demo'] );
$timezone = (string) ( $args['timezone'] ?? ( $args['model']['teacher_timezone'] ?? '' ) );
?>
<section class="dzn-tp-section" aria-labelledby="tp-calendar">
	<?php if ( 'export_unavailable' === sanitize_key( (string) ( $_GET['calendar_status'] ?? '' ) ) ) : ?><p class="dzn-tp-notice is-error" role="alert">دریافت فایل تقویم برای این کلاس ممکن نشد. لطفاً صفحه را تازه کنید و دوباره تلاش کنید.</p><?php endif; ?>
	<div class="dzn-tp-heading">
		<div><h2 id="tp-calendar">تقویم کلاس‌های آینده</h2></div>
		<div class="dzn-tp-heading__meta">
			<?php if ( '' !== $timezone ) : ?><span class="dzn-timezone-label"><span aria-hidden="true">◷</span> منطقهٔ زمانی: <?php echo esc_html( $timezone ); ?></span><?php endif; ?>
			<?php if ( $demo ) : ?><div class="dzn-tp-actions"><button type="button" data-dzn-tp-presentation>روز قبل</button><button type="button" data-dzn-tp-presentation>هفتهٔ بعد</button></div><?php endif; ?>
		</div>
	</div>
<?php if ( ! $items ) : ?>
	<div class="dzn-tp-empty-state dzn-tp-empty-state--compact" role="status"><h3>رویدادی در تقویم پیش رو نیست</h3><p>پس از تأیید یا زمان‌بندی کلاس، تاریخ و ساعت آن اینجا فهرست می‌شود.</p></div>
<?php else : ?>
<ul class="dzn-tp-calendar">
<?php foreach ( $items as $index => $item ) : ?>
	<?php
	$valid = dzn_theme_teacher_portal_calendar_valid( $item );
	$instrument = $valid ? trim( (string) ( $item['instrument'] ?? '' ) ) : '';
	$student = $valid ? trim( (string) ( $item['student'] ?? '' ) ) : '';
	$label = $valid ? (string) ( $item['label'] ?? '' ) : '';
	if ( '' === $instrument && '' === $student ) { $label_parts = array_filter( array_map( 'trim', explode( '·', $label ) ) ); $instrument = (string) ( $label_parts[0] ?? $label ); $student = (string) ( $label_parts[1] ?? '' ); }
	$term_label = $valid ? trim( (string) ( $item['term_label'] ?? '' ) ) : '';
	$lesson_number = $valid ? (int) ( $item['lesson_number'] ?? 0 ) : 0;
	$golden_label = $valid ? trim( (string) ( $item['golden_hour_label'] ?? '' ) ) : '';
	$golden_eligible = $valid && ! empty( $item['golden_hour_eligible'] );
	$details_id = 'tp-calendar-details-' . (int) $index;
	?>
	<li class="is-<?php echo esc_attr( $valid ? $item['state'] : 'unknown' ); ?>">
		<?php if ( ! $valid ) : ?>
			<p class="dzn-tp-calendar__day">رویداد در دسترس نیست</p>
			<strong class="dzn-tp-calendar__label">اطلاعات این رویداد معتبر نیست</strong>
		<?php else : ?>
		<details class="dzn-tp-calendar__item">
			<summary>
				<span class="dzn-tp-calendar__when"><span class="dzn-tp-calendar__day"><?php echo esc_html( (string) $item['day'] ); ?></span><span class="dzn-tp-calendar__time"><span class="dzn-tp-sr">ساعت </span><?php echo esc_html( (string) $item['time'] ); ?></span></span>
				<span class="dzn-tp-calendar__compact"><strong class="dzn-tp-calendar__label"><?php echo esc_html( '' !== $instrument ? $instrument : $label ); ?></strong><?php if ( '' !== $student ) : ?><span class="dzn-tp-calendar__student"><?php echo esc_html( $student ); ?></span><?php endif; ?></span>
				<?php if ( '' !== $term_label || $lesson_number > 0 || '' !== $golden_label ) : ?>
					<span class="dzn-tp-calendar__meta">
						<?php if ( '' !== $term_label ) : ?><span><?php echo esc_html( $term_label ); ?></span><?php endif; ?>
						<?php if ( $lesson_number > 0 ) : ?><span>جلسهٔ <?php echo esc_html( (string) $lesson_number ); ?></span><?php endif; ?>
						<?php if ( '' !== $golden_label ) : ?><span class="dzn-tp-golden-badge<?php echo $golden_eligible ? ' is-eligible' : ''; ?>"><?php echo esc_html( $golden_label ); ?></span><?php endif; ?>
					</span>
				<?php endif; ?>
			</summary>
			<div class="dzn-tp-calendar__detail" id="<?php echo esc_attr( $details_id ); ?>">
				<?php if ( '' !== $label ) : ?><p><?php echo esc_html( $label ); ?></p><?php endif; ?>
				<?php if ( ! empty( $item['calendar_export_url'] ) ) : ?><a class="dzn-tp-calendar-action dzn-tp-calendar-action--link" href="<?php echo esc_url( (string) $item['calendar_export_url'] ); ?>">افزودن به تقویم</a><?php endif; ?>
			</div>
		</details>
		<?php endif; ?>
	</li>
<?php endforeach; ?>
</ul>
<p class="dzn-tp-help">تاریخ و ساعت از اطلاعات تأییدشدهٔ سامانه می‌آیند؛ برای دیدن جزئیات هر رویداد آن را باز کنید.</p>
<?php endif; ?>
</section>
