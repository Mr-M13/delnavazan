<?php
$auto = ! empty( $args['auto'] );
?>
<section class="dzn-tp-tour-entry">
 <button class="dzn-button dzn-button--secondary" type="button" data-dzn-tp-open="dzn-teacher-tour">راهنمای پرتال</button>
</section>
<dialog class="dzn-tp-dialog dzn-tp-tour" id="dzn-teacher-tour"<?php if ( $auto ) : ?> data-dzn-tp-tour-auto="1"<?php endif; ?>>
 <div>
  <button class="dzn-tp-close" type="button" data-dzn-tp-close aria-label="بستن">×</button>
  <p class="dzn-tp-kicker">راهنمای کوتاه</p><h2>پرتال مدرس چطور کار می‌کند؟</h2>
  <ol class="dzn-tp-tour__steps">
   <li><strong>کلاس‌ها</strong><p>امروز فقط کارهای نیازمند توجه و کلاس‌های همان روز را می‌بینید. کلاس‌های روزهای بعد و تقویم در تب «کلاس‌ها» هستند. شروع کلاس فقط زمانی فعال می‌شود که سامانه اجازهٔ معتبر داشته باشد.</p></li>
   <li><strong>Google Calendar و Meet</strong><p>پس از فعال‌شدن اتصال Google، دلنوازان می‌تواند برنامهٔ کلاس را با تقویم هماهنگ کند و پیوند امن Google Meet را برای کلاس فراهم کند. اگر Google هنوز تنظیم نشده باشد، پرتال همان وضعیت را صادقانه نشان می‌دهد.</p></li>
   <li><strong>زمان‌های تدریس</strong><p>فقط زمان‌هایی را اعلام کنید که واقعاً امکان تدریس دارید. ساعت‌های طلایی دلنوازان راهنمای تقاضای هنرجو هستند، نه اجبار برای اعلام حضور.</p></li>
   <li><strong>مشکل کلاس و حضور</strong><p>اگر در برگزاری کلاس مشکلی رخ دهد، از همان کلاس گزارش دهید تا بررسی مدیر روی رکورد درست انجام شود.</p></li>
   <li><strong>مالی</strong><p>وضعیت حق‌التدریس و پرداخت‌ها زمانی نمایش داده می‌شود که دادهٔ معتبر مالی وجود داشته باشد؛ پرتال مبلغ یا وضعیت حدسی نشان نمی‌دهد.</p></li>
  </ol>
  <p class="dzn-tp-help">این یک مرور کوتاه است. برای آموزش گام‌به‌گام، سناریوها و تمرین‌های امن، <a href="<?php echo esc_url( add_query_arg( 'teacher-view', 'induction', home_url( '/teacher-portal/' ) ) ); ?>">راهنمای تدریس</a> را باز کنید. هر زمان خواستید می‌توانید این مرور را دوباره از دکمهٔ «راهنمای پرتال» باز کنید.</p>
  <button class="dzn-button" type="button" data-dzn-tp-close>متوجه شدم</button>
 </div>
</dialog>
