<?php
/**
 * Public booking request experience. Platform remains the data and workflow authority.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function dzn_theme_booking_options(): array {
    $class = '\Delnavazan\Platform\Core\Application\PublicBookingOptionsReadService';
    if ( ! class_exists( $class ) ) { return array(); }
    try { return ( new $class() )->introductoryInstruments(); } catch ( Throwable $e ) { return array(); }
}

function dzn_theme_render_booking_route(): void {
    $options = dzn_theme_booking_options();
    $default_timezone = wp_timezone_string();
    if ( ! $default_timezone || ! in_array( $default_timezone, timezone_identifiers_list(), true ) ) { $default_timezone = 'UTC'; }
    $requested = isset( $_GET['instrument'] ) && is_string( $_GET['instrument'] ) ? sanitize_text_field( wp_unslash( $_GET['instrument'] ) ) : '';
    $requested_name = isset( $_GET['instrument_name'] ) && is_string( $_GET['instrument_name'] ) ? sanitize_text_field( wp_unslash( $_GET['instrument_name'] ) ) : '';
    $selected = null;
    foreach ( $options as $option ) {
        if ( ( $requested !== '' && $requested === (string) $option['slug'] ) || ( $requested_name !== '' && ( $requested_name === (string) $option['name_fa'] || $requested_name === (string) $option['name_en'] ) ) ) { $selected = $option; break; }
    }
    ?>
    <main id="main-content" class="site-main dzn-booking" tabindex="-1">
        <div class="dzn-container dzn-booking__container">
            <header class="dzn-booking__heading">
                <p class="dzn-eyebrow">شروع مسیر موسیقی شما</p>
                <h1>درخواست جلسهٔ معارفه</h1>
                <p>ساز و زمان‌های پیشنهادی‌تان را انتخاب کنید. تیم دلنوازان درخواست شما را بررسی می‌کند و برای هماهنگی با شما تماس می‌گیرد.</p>
            </header>
            <?php if ( ! $options ) : ?>
                <section class="dzn-booking__notice" role="status">
                    <h2>ثبت درخواست موقتاً در دسترس نیست</h2>
                    <p>گزینه‌های فعال جلسهٔ معارفه از سامانهٔ آموزشی دریافت نشد. لطفاً کمی بعد دوباره تلاش کنید یا با دلنوازان تماس بگیرید.</p>
                    <a href="<?php echo esc_url( home_url( '/' ) ); ?>">بازگشت به صفحهٔ اصلی</a>
                </section>
            <?php else : ?>
                <section class="dzn-booking__card" data-dzn-booking>
                    <nav class="dzn-booking__progress" aria-label="مراحل درخواست">
                        <ol>
                            <li class="is-current" data-progress="instrument">ساز</li>
                            <li data-progress="availability">زمان</li>
                            <li data-progress="contact">اطلاعات</li>
                        </ol>
                    </nav>
                    <div class="dzn-booking__error" data-error role="alert" hidden></div>
                    <section class="dzn-booking__step is-active" data-step="instrument" aria-labelledby="dzn-booking-instrument-title">
                        <p class="dzn-booking__kicker">مرحلهٔ اول · انتخاب ساز</p>
                        <h2 id="dzn-booking-instrument-title" tabindex="-1">با چه سازی می‌خواهید شروع کنید؟</h2>
                        <div class="dzn-booking__instrument-grid" role="group" aria-label="انتخاب ساز">
                            <?php foreach ( $options as $option ) : ?>
                                <button class="dzn-booking__instrument<?php echo $selected && (int) $selected['id'] === (int) $option['id'] ? ' is-selected' : ''; ?>" type="button" data-instrument-choice="<?php echo esc_attr( (string) $option['id'] ); ?>" aria-pressed="<?php echo $selected && (int) $selected['id'] === (int) $option['id'] ? 'true' : 'false'; ?>">
                                    <span class="dzn-booking__instrument-mark" aria-hidden="true">♫</span>
                                    <span class="dzn-booking__instrument-name"><?php echo esc_html( $option['name_fa'] ?: $option['name_en'] ); ?></span>
                                    <span class="dzn-booking__instrument-action">انتخاب ساز</span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <select id="dzn-booking-instrument" data-instrument required aria-label="ساز موردنظر" class="dzn-booking__instrument-source">
                            <option value="">انتخاب ساز</option>
                            <?php foreach ( $options as $option ) : ?>
                                <option value="<?php echo esc_attr( (string) $option['id'] ); ?>" data-course="<?php echo esc_attr( (string) $option['course_id'] ); ?>" data-duration="<?php echo esc_attr( (string) $option['duration_minutes'] ); ?>" data-buffer="<?php echo esc_attr( (string) $option['buffer_minutes'] ); ?>" <?php selected( $selected && (int) $selected['id'] === (int) $option['id'] ); ?>>
                                    <?php echo esc_html( $option['name_fa'] ?: $option['name_en'] ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="dzn-booking__help">جلسهٔ معارفه رایگان است؛ انتخاب ساز هنوز زمان کلاس را رزرو نمی‌کند.</p>
                        <div class="dzn-booking__actions">
                            <a class="dzn-booking__back" href="<?php echo esc_url( home_url( '/' ) ); ?>">بازگشت به صفحهٔ اصلی</a>
                            <button class="dzn-booking__button" type="button" data-next="availability">انتخاب زمان</button>
                        </div>
                    </section>
                    <section class="dzn-booking__step" data-step="availability" aria-labelledby="dzn-booking-availability-title" hidden>
                        <p class="dzn-booking__kicker">مرحلهٔ دوم · زمان جلسه</p>
                        <h2 id="dzn-booking-availability-title" tabindex="-1">چه زمانی برای شما مناسب است؟</h2>
                        <div class="dzn-booking__timezone" data-timezone-note>
                            <strong>منطقهٔ زمانی شما</strong>
                            <label class="screen-reader-text" for="dzn-booking-timezone">منطقهٔ زمانی (نام IANA)</label>
                            <input id="dzn-booking-timezone" data-timezone type="text" value="<?php echo esc_attr( $default_timezone ); ?>" autocomplete="off" required>
                            <p>منطقهٔ زمانی دستگاه شما به‌طور خودکار تشخیص داده شد. همهٔ ساعت‌ها در همین منطقه نمایش داده می‌شوند و برای مدرس خودکار تبدیل خواهند شد. در صورت نیاز می‌توانید آن را ویرایش کنید.</p>
                        </div>
                        <div class="dzn-booking__calendar">
                            <div class="dzn-booking__calendar-heading">
                                <button type="button" class="dzn-booking__calendar-nav" data-calendar-prev aria-label="ماه قبل">‹</button>
                                <h3 data-calendar-label aria-live="polite"></h3>
                                <button type="button" class="dzn-booking__calendar-nav" data-calendar-next aria-label="ماه بعد">›</button>
                            </div>
                            <div class="dzn-booking__calendar-grid" data-calendar-weekdays aria-hidden="true"></div>
                            <div class="dzn-booking__calendar-grid" data-calendar role="group" aria-label="انتخاب روز" tabindex="-1"></div>
                        </div>
                        <section class="dzn-booking__day-times" data-day-times hidden aria-labelledby="dzn-booking-day-title">
                            <h3 id="dzn-booking-day-title" data-day-title>زمان‌های پیشنهادی</h3>
                            <p class="dzn-booking__help">یک ساعت را انتخاب کنید تا به فهرست اولویت‌ها اضافه شود. رنگ‌ها پس از بررسی سامانهٔ زمان‌بندی نمایش داده می‌شوند.</p>
                            <div class="dzn-booking__time-options" data-time-options></div>
                        </section>
                        <div class="dzn-booking__preference-heading">
                            <h3>اولویت‌های شما</h3><span data-preference-count>۰ از ۳</span>
                        </div>
                        <ul class="dzn-booking__times" data-times aria-live="polite"></ul>
                        <div class="dzn-booking__availability-key" aria-label="راهنمای وضعیت پیشنهادی زمان‌ها">
                            <span><i class="is-strong"></i>تناسب زمانی خوب</span>
                            <span><i class="is-possible"></i>امکان محدود یا احتمالی</span>
                            <span><i class="is-none"></i>تطابق فعلی ندارد؛ قابل درخواست</span>
                        </div>
                        <div class="dzn-booking__actions">
                            <button class="dzn-booking__button dzn-booking__button--secondary" type="button" data-back="instrument">بازگشت به انتخاب ساز</button>
                            <button class="dzn-booking__button" type="button" data-next="contact">ادامه به اطلاعات شما</button>
                        </div>
                    </section>
                    <section class="dzn-booking__step" data-step="contact" aria-labelledby="dzn-booking-contact-title" hidden>
                        <p class="dzn-booking__kicker">مرحلهٔ سوم · اطلاعات شما</p>
                        <h2 id="dzn-booking-contact-title" tabindex="-1">چطور با شما در تماس باشیم؟</h2>
                        <div class="dzn-booking__fields">
                            <div><label for="dzn-booking-name">نام کامل</label><input id="dzn-booking-name" data-contact="full_name" autocomplete="name" maxlength="191" required></div>
                            <div><label for="dzn-booking-email">ایمیل</label><input id="dzn-booking-email" data-contact="email" type="email" autocomplete="email" maxlength="191" required><p class="dzn-booking__field-help">برای پیگیری درخواست و ادامهٔ مسیر هنرجویی از این ایمیل استفاده می‌کنیم.</p></div>
                            <div><label for="dzn-booking-country">کشور محل زندگی</label><select id="dzn-booking-country" data-contact="country" autocomplete="country" required>
                                <option value="">انتخاب کشور</option>
                                <option value="AU">استرالیا</option><option value="BR">برزیل</option><option value="CA">کانادا</option><option value="FR">فرانسه</option><option value="DE">آلمان</option><option value="IR">ایران</option><option value="NZ">نیوزیلند</option><option value="SE">سوئد</option><option value="TR">ترکیه</option><option value="AE">امارات متحدهٔ عربی</option><option value="GB">بریتانیا</option><option value="US">ایالات متحده</option>
                            </select></div>
                            <div><label for="dzn-booking-city">شهر محل زندگی</label><input id="dzn-booking-city" data-contact="city" autocomplete="address-level2" maxlength="191" required></div>
                            <div class="dzn-booking__field--wide"><label for="dzn-booking-mobile">شمارهٔ موبایل</label><input id="dzn-booking-mobile" data-contact="mobile" type="tel" autocomplete="tel" placeholder="+61 ..." maxlength="32" required><p class="dzn-booking__field-help">کد کشور را هم وارد کنید؛ نمونه برای استرالیا ‎+61.</p></div>
                        </div>
                        <fieldset class="dzn-booking__whatsapp">
                            <legend>واتساپ</legend>
                            <label><input type="checkbox" data-whatsapp-same checked> همین شماره برای واتساپ هم استفاده می‌شود</label>
                            <p class="dzn-booking__field-help">برای هماهنگی و اطلاع‌رسانی مربوط به کلاس‌ها از واتساپ شما استفاده می‌کنیم.</p>
                            <div data-whatsapp-extra hidden><label for="dzn-booking-whatsapp">شمارهٔ واتساپ با کد کشور</label><input id="dzn-booking-whatsapp" data-whatsapp type="tel" maxlength="32" placeholder="+61 ..."></div>
                        </fieldset>
                        <label class="dzn-booking__privacy"><input type="checkbox" data-privacy required> موافقم اطلاعات تماس و زمان‌های پیشنهادی من برای بررسی این درخواست در دلنوازان ثبت و استفاده شود. درخواست ثبت‌شده تا ۲۴ ماه نگهداری می‌شود.</label>
                        <div class="dzn-booking__inline-review">
                            <h3>خلاصهٔ درخواست</h3><div data-review class="dzn-booking__review"></div>
                            <aside class="dzn-booking__notice"><h4>پرداختی برای جلسهٔ معارفه ندارید</h4><p>این جلسه رایگان است و اکنون پرداختی انجام نمی‌شود. اگر پس از جلسه ادامه دهید، هزینه و کلاس‌های منظم با شما هماهنگ می‌شود. این درخواست هنوز زمان را تأیید یا حساب هنرجویی ایجاد نمی‌کند.</p></aside>
                        </div>
                        <div class="dzn-booking__actions">
                            <button class="dzn-booking__button dzn-booking__button--secondary" type="button" data-back="availability">بازگشت به انتخاب زمان</button>
                            <button class="dzn-booking__button" type="button" data-submit>ثبت درخواست جلسهٔ معارفه</button>
                        </div>
                    </section>
                    
