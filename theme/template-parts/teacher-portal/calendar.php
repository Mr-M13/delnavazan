<?php
$items   = is_array( $args['items'] ?? null ) ? $args['items'] : array();
$context = dzn_theme_teacher_portal_context();
$demo    = ! empty( $context['demo'] );
?>
<section class="dzn-tp-section" aria-labelledby="tp-calendar"><div class="dzn-tp-heading"><div><p class="dzn-tp-kicker">نگاه پیش رو</p><h2 id="tp-calendar">تقویم کلاس‌های آینده</h2></div><?php if ( $demo ) : ?><div class="dzn-tp-actions"><button type="button" data-dzn-tp-presentation>روز قبل</button><button type="button" data-dzn-tp-presentation>هفتهٔ بعد</button></div><?php endif; ?></div>
<?php if ( ! $items ) : ?>
	<div class="dzn-tp-empty" role="status"><h3>رویدادی در تقویم پیش رو نیست</h3><p>پس از تأیید یا زمان‌بندی کلاس، تاریخ و ساعت آن اینجا فهرست می‌شود.</p></div>
<?php else : ?>
<ul class="dzn-tp-calendar">
<?php foreach ( $items as $item ) : $valid = dzn_theme_teacher_portal_calendar_valid( $item ); ?>
	<li class="is-<?php echo esc_attr( $valid ? $item['state'] : 'unknown' ); ?>"><p class="dzn-tp-calendar__day"><?php echo esc_html( $valid ? $item['day'] : 'رویداد در دسترس نیست' ); ?></p><p class="dzn-tp-calendar__time"><span class="dzn-tp-sr">ساعت </span><?php echo esc_html( $valid ? $item['time'] : '—' ); ?></p><strong><?php echo esc_html( $valid ? ( $item['label'] ?? '' ) : 'اطلاعات این رویداد معتبر نیست' ); ?></strong></li>
<?php endforeach; ?>
</ul>
<p class="dzn-tp-help">برچسب نوشتاری هر رویداد نوع آن را مشخص می‌کند و تاریخ و ساعت از اطلاعات تأییدشدهٔ سامانه می‌آیند؛ پوسته زمان‌بندی را محاسبه نمی‌کند.</p>
<?php endif; ?></section>
