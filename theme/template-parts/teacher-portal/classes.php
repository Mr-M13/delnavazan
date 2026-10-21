<?php
$class_status = sanitize_key( (string) ( $_GET['class_status'] ?? '' ) );
if ( 'delivery_issue_recorded' === $class_status ) : ?>
<p class="dzn-tp-notice is-success" role="status" aria-live="polite">گزارش مشکل برگزاری کلاس ثبت شد.</p>
<?php elseif ( 'delivery_issue_unavailable' === $class_status ) : ?>
<p class="dzn-tp-notice is-error" role="alert" aria-live="polite">ثبت گزارش برای این کلاس در حال حاضر امکان‌پذیر نیست.</p>
<?php elseif ( 'join_unavailable' === $class_status ) : ?>
<p class="dzn-tp-notice is-error" role="alert" aria-live="polite">پیوند امن Meet در دسترس نیست یا زمان ورود به کلاس نرسیده است. لطفاً دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.</p>
<?php endif; ?>
<?php
$items = is_array( $args['items'] ?? null ) ? $args['items'] : array();
$timezone = $args['timezone'] ?? ( $args['model']['teacher_timezone'] ?? '' );
?>
<section class="dzn-tp-section" aria-labelledby="tp-classes">
 <div class="dzn-tp-heading">
  <div>
   <p class="dzn-tp-kicker">برنامهٔ روز</p>
   <h2 id="tp-classes">کلاس‌های امروز</h2>
  </div>
  <div class="dzn-tp-heading__meta">
   <span class="dzn-tp-count-badge"><?php echo esc_html( sprintf( '%d کلاس', count( $items ) ) ); ?></span>
   <?php if ( $timezone ) : ?>
    <span class="dzn-timezone-label"><span aria-hidden="true">◷</span> <?php echo esc_html( $timezone ); ?></span>
   <?php endif; ?>
  </div>
 </div>

 <?php if ( empty( $items ) ) : ?>
  <div class="dzn-tp-empty-state" role="status">
   <div class="dzn-tp-empty-state__icon" aria-hidden="true">📅</div>
   <h3>کلاس پیش‌رویی در برنامه نیست</h3>
   <p>در حال حاضر هیچ کلاسی برای روزهای آینده در برنامهٔ شما ثبت نشده است. برای بررسی یا تغییر ساعات حضور خود، به بخش تنظیمات مراجعه کنید.</p>
   <div class="dzn-tp-empty-state__actions">
    <a href="<?php echo esc_url( add_query_arg( 'teacher-view', 'settings', home_url( '/teacher-portal/' ) ) ); ?>" class="dzn-button dzn-button--secondary">تنظیمات ساعات تدریس</a>
   </div>
  </div>
 <?php else : ?>
  <div class="dzn-tp-class-list">
  <?php foreach ( $items as $index => $item ) :
   $valid = dzn_theme_teacher_portal_class_valid( $item );
   $state = $valid ? $item['state'] : 'unknown';
   $date_display = $valid ? ( $item['date'] ?? '' ) : '';
   if ( empty( $date_display ) && $valid && ! empty( $item['schedule_summary'] ) ) {
    $parts = explode( '،', $item['schedule_summary'] );
    $date_display = trim( $parts[0] );
   }
  ?>
   <article class="dzn-tp-class is-<?php echo esc_attr( $state ); ?>" aria-labelledby="tp-class-title-<?php echo esc_attr( $index ); ?>">
    <div class="dzn-tp-class__time-col">
     <time class="dzn-tp-class__time" datetime="<?php echo esc_attr( $valid ? ( $item['starts_at_utc'] ?? '' ) : '' ); ?>">
      <span class="dzn-tp-time-clock"><?php echo esc_html( $valid ? $item['time'] : '—' ); ?></span>
      <?php if ( $date_display ) : ?>
       <span class="dzn-tp-time-date"><?php echo esc_html( $date_display ); ?></span>
      <?php endif; ?>
     </time>
    </div>
    <div class="dzn-tp-class__info-col">
     <div class="dzn-tp-class__title-row">
      <h3 id="tp-class-title-<?php echo esc_attr( $index ); ?>" class="dzn-tp-class__student">
       <?php echo esc_html( $valid ? $item['student'] : 'کلاس در دسترس نیست' ); ?>
      </h3>
      <span class="dzn-tp-state dzn-tp-state--<?php echo esc_attr( $state ); ?>">
       <span class="dzn-tp-state__bullet" aria-hidden="true">●</span>
       <?php echo esc_html( $valid ? $item['status'] : 'اقدام‌ها غیرفعال‌اند' ); ?>
      </span>
     </div>
     <p class="dzn-tp-class__course-line">
      <span class="dzn-tp-class__course"><?php echo esc_html( $valid ? $item['course'] : 'وضعیت نامعتبر یا ناقص' ); ?></span>
      <?php if ( $valid && ! empty( $item['progress'] ) ) : ?>
       <span class="dzn-tp-class__dot" aria-hidden="true">·</span>
       <span class="dzn-tp-class__progress"><?php echo esc_html( $item['progress'] ); ?></span>
      <?php endif; ?>
     </p>
     <?php if ( $valid && ! empty( $item['schedule_summary'] ) ) : ?>
      <p class="dzn-tp-class__schedule-summary">
       <span aria-hidden="true">◷</span> <?php echo esc_html( $item['schedule_summary'] ); ?>
      </p>
     <?php endif; ?>
    </div>
    <?php if ( $valid ) : ?>
     <div class="dzn-tp-actions">
      <?php if ( true === $item['start_available'] ) : ?>
       <form method="post" target="_blank" action="<?php echo esc_url( (string) ( $item['join_action_url'] ?? '' ) ); ?>" class="dzn-tp-join-form">
        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( (string) ( $item['join_nonce'] ?? '' ) ); ?>">
        <?php if ( ! empty( $item['confirmation_uid'] ) ) : ?>
         <input type="hidden" name="confirmation_uid" value="<?php echo esc_attr( (string) $item['confirmation_uid'] ); ?>">
        <?php else : ?>
         <input type="hidden" name="lesson_uid" value="<?php echo esc_attr( (string) ( $item['lesson_uid'] ?? '' ) ); ?>">
         <input type="hidden" name="schedule_version_uid" value="<?php echo esc_attr( (string) ( $item['schedule_version_uid'] ?? '' ) ); ?>">
        <?php endif; ?>
        <button class="dzn-button dzn-tp-btn-join" type="submit">
         <span aria-hidden="true">📹</span> شروع کلاس
        </button>
       </form>
      <?php elseif ( ! empty( $item['confirmation_uid'] ) ) : ?>
       <button class="dzn-button dzn-tp-btn-join" type="button" disabled aria-describedby="tp-join-<?php echo esc_attr( $index ); ?>">
        <span aria-hidden="true">📹</span> شروع کلاس
       </button>
      <?php else : ?>
       <button class="dzn-button dzn-tp-btn-join" type="button" disabled aria-describedby="tp-join-<?php echo esc_attr( $index ); ?>">
        <span aria-hidden="true">📹</span> شروع کلاس
       </button>
      <?php endif; ?>
      <?php if ( true === $item['details_available'] ) : ?>
       <button class="dzn-button dzn-button--secondary dzn-tp-btn-details" type="button" aria-expanded="false" aria-controls="tp-details-<?php echo esc_attr( $index ); ?>" data-dzn-tp-toggle="tp-details-<?php echo esc_attr( $index ); ?>">
        جزئیات
       </button>
      <?php endif; ?>
     </div>
     <?php if ( ! empty( $item['confirmation_uid'] ) && ! empty( $item['join_message'] ) ) : ?>
      <p id="tp-join-<?php echo esc_attr( $index ); ?>" class="dzn-portal-help"><?php echo esc_html( (string) $item['join_message'] ); ?></p>
     <?php elseif ( empty( $item['start_available'] ) ) : ?>
      <p id="tp-join-<?php echo esc_attr( $index ); ?>" class="dzn-portal-help">پیوند ورود در بازهٔ شروع کلاس فعال می‌شود.</p>
     <?php endif; ?>
    <?php endif; ?>
    <?php if ( $valid && true === $item['details_available'] ) : ?>
     <div id="tp-details-<?php echo esc_attr( $index ); ?>" class="dzn-tp-class-details" hidden>
      <div class="dzn-tp-detail-grid">
       <section class="dzn-tp-detail-card">
        <h4>خلاصهٔ هنرجو</h4>
        <p><?php echo esc_html( $item['student_summary'] ); ?></p>
        <p><?php echo esc_html( $item['schedule_summary'] ); ?></p>
        <p>یادداشت خصوصی پیشین: <?php echo esc_html( $item['previous_private_note'] ); ?></p>
       </section>
       <section class="dzn-tp-detail-card">
        <h4>مدیریت کلاس</h4>
        <p class="dzn-tp-detail-help">اگر هنرجو حاضر نشد یا مشکلی در برگزاری کلاس رخ داد:</p>
        <form method="post" action="<?php echo esc_url( (string) ( $item['delivery_issue_action_url'] ?? '' ) ); ?>">
         <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( (string) ( $item['delivery_issue_nonce'] ?? '' ) ); ?>">
         <input type="hidden" name="lesson_uid" value="<?php echo esc_attr( (string) ( $item['lesson_uid'] ?? '' ) ); ?>">
         <input type="hidden" name="schedule_version_uid" value="<?php echo esc_attr( (string) ( $item['schedule_version_uid'] ?? '' ) ); ?>">
         <button class="dzn-button dzn-button--secondary dzn-button--danger" type="submit">گزارش مشکل در برگزاری کلاس</button>
        </form>
        <button type="button" class="dzn-button dzn-button--secondary" data-dzn-tp-presentation>درخواست بررسی زمان</button>
       </section>
      </div>
      <section class="dzn-tp-private-note">
       <h4>یادداشت خصوصی مدرس</h4>
       <p>فقط برای مدرس و تیم مجاز؛ برای هنرجو نمایش داده نمی‌شود.</p>
       <textarea rows="3" aria-label="یادداشت خصوصی مدرس"></textarea>
      </section>
      <section class="dzn-tp-practice">
       <h4>تمرین و تکلیف برای هنرجو</h4>
       <p>پس از اتصال امن، این متن برای هنرجو قابل مشاهده خواهد بود.</p>
       <textarea rows="3" aria-label="تمرین و تکلیف قابل مشاهده برای هنرجو"></textarea>
      </section>
      <?php if ( 'replacement' === $item['state'] ) : ?>
       <p class="dzn-tp-context">این جلسه در مدل نمایشی به‌عنوان جایگزین مجاز آمده است؛ پوسته استحقاق را ایجاد یا تعیین نمی‌کند.</p>
      <?php endif; ?>
     </div>
    <?php endif; ?>
   </article>
  <?php endforeach; ?>
  </div>
 <?php endif; ?>
</section>
