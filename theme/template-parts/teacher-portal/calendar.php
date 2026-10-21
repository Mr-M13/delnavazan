<?php
$items = is_array( $args['items'] ?? null ) ? $args['items'] : array();
$timezone = $args['timezone'] ?? ( $args['model']['teacher_timezone'] ?? '' );
?>
<section class="dzn-tp-section" aria-labelledby="tp-calendar">
 <div class="dzn-tp-heading">
  <div>
   <p class="dzn-tp-kicker">نگاه پیش رو</p>
   <h2 id="tp-calendar">تقویم کلاس‌های آینده</h2>
  </div>
  <div class="dzn-tp-heading__meta">
   <?php if ( $timezone ) : ?>
    <span class="dzn-timezone-label"><span aria-hidden="true">◷</span> <?php echo esc_html( $timezone ); ?></span>
   <?php endif; ?>
   <div class="dzn-tp-actions">
    <button type="button" class="dzn-button dzn-button--secondary dzn-button--small" data-dzn-tp-presentation>روز قبل</button>
    <button type="button" class="dzn-button dzn-button--secondary dzn-button--small" data-dzn-tp-presentation>هفتهٔ بعد</button>
   </div>
  </div>
 </div>
 <?php if ( empty( $items ) ) : ?>
  <div class="dzn-tp-empty-state dzn-tp-empty-state--compact" role="status">
   <p>کلاسی در تقویم آینده ثبت نشده است.</p>
  </div>
 <?php else : ?>
  <div class="dzn-tp-calendar" role="list">
  <?php foreach ( $items as $item ) : $valid = dzn_theme_teacher_portal_calendar_valid( $item ); ?>
   <article role="listitem" class="is-<?php echo esc_attr( $valid ? $item['state'] : 'unknown' ); ?>">
    <p class="dzn-tp-cal-day"><?php echo esc_html( $valid ? $item['day'] : '' ); ?></p>
    <time class="dzn-tp-cal-time"><?php echo esc_html( $valid ? $item['time'] : '' ); ?></time>
    <strong class="dzn-tp-cal-label"><?php echo esc_html( $valid ? ( $item['label'] ?? '' ) : 'رویداد در دسترس نیست' ); ?></strong>
   </article>
  <?php endforeach; ?>
  </div>
 <?php endif; ?>
 <p class="dzn-tp-help">رنگ‌ها همراه با عنوان نوشتاری‌اند و هیچ زمان‌بندی در پوسته محاسبه نمی‌شود.</p>
</section>
