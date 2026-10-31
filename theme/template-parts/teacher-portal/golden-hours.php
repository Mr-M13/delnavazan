<?php
/**
 * Teacher-local golden-hour bonus visual.
 *
 * Expected args:
 * - model    The Teacher portal view model.
 * - show_cta Whether to link to the availability form.
 */
$m = is_array( $args['model'] ?? null ) ? $args['model'] : array();
$golden = is_array( $m['golden_bonus'] ?? null ) ? $m['golden_bonus'] : array();
$state = (string) ( $golden['state'] ?? 'unavailable' );
$timezone_label = (string) ( $golden['timezone_label'] ?? ( $m['profile']['timezone_label'] ?? '' ) );
$show_cta = ! empty( $args['show_cta'] );
$day_labels = array( 1 => 'دوشنبه', 2 => 'سه‌شنبه', 3 => 'چهارشنبه', 4 => 'پنجشنبه', 5 => 'جمعه', 6 => 'شنبه', 7 => 'یکشنبه' );
?>
<section class="dzn-tp-section dzn-tp-golden-section" aria-labelledby="tp-golden">
	<div class="dzn-tp-heading">
		<div><p class="dzn-tp-kicker">پاداش ساعت طلایی</p><h2 id="tp-golden">ساعت‌های طلایی به وقت شما</h2></div>
		<?php if ( 'active' === $state ) : ?><span class="dzn-tp-golden-rate" dir="ltr" aria-label="درصد پاداش ساعت طلایی">+<?php echo esc_html( (string) ( $golden['percentage_label'] ?? '0' ) ); ?>%</span><?php endif; ?>
	</div>
	<p class="dzn-tp-help">این نمایش فقط بازه‌های پاداش‌دار را به وقت محلی شما<?php echo '' !== $timezone_label ? ' (<bdi>' . esc_html( $timezone_label ) . '</bdi>)' : ''; ?> نشان می‌دهد. زمان آکادمی یا دسترسی سایر مدرسان نمایش داده نمی‌شود.</p>
	<?php if ( 'inactive' === $state ) : ?>
		<div class="dzn-tp-empty-state dzn-tp-empty-state--compact" role="status"><h3>پاداش ساعت طلایی در حال حاضر فعال نیست</h3><p>هر زمان که مدیر آکادمی پاداش را فعال کند، بازه‌های آن به وقت محلی شما همین‌جا نمایش داده می‌شود.</p></div>
	<?php elseif ( 'unavailable' === $state ) : ?>
		<p class="dzn-tp-notice is-warning" role="status">اطلاعات پاداش ساعت طلایی در دسترس نیست. لطفاً بعداً دوباره بررسی کنید.</p>
	<?php elseif ( empty( $golden['has_windows'] ) ) : ?>
		<p class="dzn-tp-help">در هفت روز آینده بازهٔ پاداش‌داری ثبت نشده است.</p>
	<?php else : ?>
		<div class="dzn-tp-golden-week" role="list" tabindex="0" aria-label="بازه‌های پاداش‌دار به وقت محلی شما">
			<?php foreach ( (array) ( $golden['days'] ?? array() ) as $day ) : ?>
				<article class="dzn-tp-golden-day<?php echo ! empty( $day['has_window'] ) ? ' is-active' : ''; ?>" role="listitem">
					<header class="dzn-tp-golden-day__head">
						<span class="dzn-tp-golden-day__name"><?php echo esc_html( $day_labels[ (int) ( $day['weekday'] ?? 0 ) ] ?? '' ); ?></span>
						<span class="dzn-tp-golden-day__date"><?php echo esc_html( (string) ( $day['date_label'] ?? $day['date'] ?? '' ) ); ?></span>
					</header>
					<?php if ( ! empty( $day['intervals'] ) ) : ?>
						<div class="dzn-tp-golden-day__slots">
							<?php foreach ( (array) $day['intervals'] as $interval ) : ?>
								<div class="dzn-tp-golden-slot">
									<span class="dzn-tp-golden-slot__time" dir="ltr"><?php echo esc_html( (string) ( $interval['label'] ?? '' ) ); ?></span>
									<span class="dzn-tp-golden-slot__bonus" dir="ltr">+<?php echo esc_html( (string) ( $interval['percentage_label'] ?? $golden['percentage_label'] ?? '0' ) ); ?>%</span>
								</div>
							<?php endforeach; ?>
						</div>
					<?php else : ?>
						<p class="dzn-tp-golden-day__empty">—</p>
					<?php endif; ?>
				</article>
			<?php endforeach; ?>
		</div>
		<p class="dzn-tp-help">این بازه‌ها به وقت محلی شما هستند. برای دریافت پاداش، در دسترسی هفتگی خود همین ساعت‌ها را اضافه کنید.</p>
		<?php if ( $show_cta ) : ?><a class="dzn-button dzn-button--secondary" href="#tp-availability">افزودن دسترسی در ساعت‌های طلایی</a><?php endif; ?>
	<?php endif; ?>
</section>
