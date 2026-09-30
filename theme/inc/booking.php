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
                            <li class="is-current" data-progress="instrument">ساز و زمان</li>
                            <li data-progress="contact">اطلاعات شما</li>
                            <li data-progress="review">بازبینی</li>
                        </ol>
                    </nav>
                    <div class="dzn-booking__error" data-error role="alert" hidden></div>
                    <section class="dzn-booking__step is-active" data-step="instrument" aria-labelledby="dzn-booking-instrument-title">
                        <p class="dzn-booking__kicker">مرحلهٔ اول</p>
                        <h2 id="dzn-booking-instrument-title">ساز و زمان‌های پیشنهادی را انتخاب کنید</h2>
                        <label for="dzn-booking-instrument">ساز موردنظر</label>
                        <select id="dzn-booking-instrument" data-instrument required>
                            <option value="">انتخاب ساز</option>
                            <?php foreach ( $options as $option ) : ?>
                                <option value="<?php echo esc_attr( (string) $option['id'] ); ?>" data-course="<?php echo esc_attr( (string) $option['course_id'] ); ?>" data-duration="<?php echo esc_attr( (string) $option['duration_minutes'] ); ?>" data-buffer="<?php echo esc_attr( (string) $option['buffer_minutes'] ); ?>" <?php selected( $selected && (int) $selected['id'] === (int) $option['id'] ); ?>>
                                    <?php echo esc_html( $option['name_fa'] ?: $option['name_en'] ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="dzn-booking__help">جلسهٔ معارفه برای آشنایی با مدرس و گفت‌وگو دربارهٔ مسیر یادگیری است.</p>
                        <div class="dzn-booking__timezone" data-timezone-note>
                            <strong>منطقهٔ زمانی شما</strong>
                            <label class="screen-reader-text" for="dzn-booking-timezone">منطقهٔ زمانی (نام IANA)</label>
                            <input id="dzn-booking-timezone" data-timezone type="text" value="<?php echo esc_attr( $default_timezone ); ?>" autocomplete="off" required>
                            <p>تمام زمان‌ها در منطقهٔ زمانی شما نمایش داده می‌شوند و برای بررسی به ساعت محلی مدرس تبدیل خواهند شد.</p>
                        </div>
                        <div class="dzn-booking__slot-picker">
                            <div>
                                <label for="dzn-booking-date">تاریخ پیشنهادی</label>
                                <input id="dzn-booking-date" data-date type="date" required>
                            </div>
                            <div>
                                <label for="dzn-booking-time">ساعت پیشنهادی</label>
                                <input id="dzn-booking-time" data-time type="time" required>
                            </div>
                            <button class="dzn-booking__button dzn-booking__button--secondary" type="button" data-add-time>افزودن زمان</button>
                        </div>
                        <p class="dzn-booking__help">تا سه زمان را با اولویت دلخواهتان اضافه کنید. نمایش زمان به معنی رزرو یا تأیید کلاس نیست.</p>
                        <ul class="dzn-booking__times" data-times aria-live="polite"></ul>
                        <div class="dzn-booking__availability-key" aria-label="راهنمای وضعیت زمان‌ها">
                            <span><i class="is-strong"></i>تناسب زمانی خوب</span>
                            <span><i class="is-possible"></i>امکان محدود یا احتمالی</span>
                            <span><i class="is-none"></i>بدون تطابق فعلی؛ همچنان قابل درخواست</span>
                        </div>
                        <div class="dzn-booking__actions">
                            <a class="dzn-booking__back" href="<?php echo esc_url( home_url( '/' ) ); ?>">بازگشت به صفحهٔ اصلی</a>
                            <button class="dzn-booking__button" type="button" data-next="contact">ادامه</button>
                        </div>
                    </section>
                    <section class="dzn-booking__step" data-step="contact" aria-labelledby="dzn-booking-contact-title" hidden>
                        <p class="dzn-booking__kicker">مرحلهٔ دوم</p>
                        <h2 id="dzn-booking-contact-title">چطور با شما در تماس باشیم؟</h2>
                        <div class="dzn-booking__fields">
                            <div><label for="dzn-booking-name">نام کامل</label><input id="dzn-booking-name" data-contact="full_name" autocomplete="name" maxlength="191" required></div>
                            <div><label for="dzn-booking-email">ایمیل</label><input id="dzn-booking-email" data-contact="email" type="email" autocomplete="email" maxlength="191" required></div>
                            <div><label for="dzn-booking-mobile">شمارهٔ موبایل با کد کشور</label><input id="dzn-booking-mobile" data-contact="mobile" type="tel" autocomplete="tel" placeholder="+61 ..." maxlength="32" required></div>
                            <div><label for="dzn-booking-country">کد دوحرفی کشور</label><input id="dzn-booking-country" data-contact="country" autocomplete="country" placeholder="AU" pattern="[A-Za-z]{2}" maxlength="2" required></div>
                            <div><label for="dzn-booking-city">شهر محل زندگی</label><input id="dzn-booking-city" data-contact="city" autocomplete="address-level2" maxlength="191" required></div>
                            <div><label for="dzn-booking-language">زبان ترجیحی برای ارتباط</label><select id="dzn-booking-language" data-contact="communication_language"><option value="fa">فارسی</option><option value="en">English</option></select></div>
                        </div>
                        <fieldset class="dzn-booking__whatsapp">
                            <legend>واتساپ</legend>
                            <label><input type="checkbox" data-whatsapp-same checked> همین شماره برای واتساپ هم استفاده می‌شود</label>
                            <div data-whatsapp-extra hidden><label for="dzn-booking-whatsapp">شمارهٔ واتساپ با کد کشور</label><input id="dzn-booking-whatsapp" data-whatsapp type="tel" maxlength="32" placeholder="+61 ..."></div>
                        </fieldset>
                        <label class="dzn-booking__privacy"><input type="checkbox" data-privacy required> موافقم اطلاعات تماس و زمان‌های پیشنهادی من برای بررسی این درخواست در دلنوازان ثبت و استفاده شود. درخواست ثبت‌شده تا ۲۴ ماه نگهداری می‌شود.</label>
                        <div class="dzn-booking__actions">
                            <button class="dzn-booking__button dzn-booking__button--secondary" type="button" data-back="instrument">بازگشت</button>
                            <button class="dzn-booking__button" type="button" data-next="review">بازبینی درخواست</button>
                        </div>
                    </section>
                    <section class="dzn-booking__step" data-step="review" aria-labelledby="dzn-booking-review-title" hidden>
                        <p class="dzn-booking__kicker">مرحلهٔ سوم</p>
                        <h2 id="dzn-booking-review-title">درخواست خود را بازبینی کنید</h2>
                        <div data-review class="dzn-booking__review"></div>
                        <aside class="dzn-booking__notice">
                            <h3>جلسهٔ معارفه رایگان است</h3>
                            <p>اکنون هیچ پرداختی انجام نمی‌شود. اگر پس از جلسه تصمیم به ادامه بگیرید، هزینه و برنامهٔ کلاس‌های منظم جداگانه با شما هماهنگ می‌شود. ثبت این درخواست به معنی تأیید زمان یا ایجاد حساب هنرجویی نیست.</p>
                        </aside>
                        <div class="dzn-booking__actions">
                            <button class="dzn-booking__button dzn-booking__button--secondary" type="button" data-back="contact">بازگشت و ویرایش</button>
                            <button class="dzn-booking__button" type="button" data-submit>ثبت درخواست جلسهٔ معارفه</button>
                        </div>
                    </section>
                    <section class="dzn-booking__step dzn-booking__success" data-step="success" aria-labelledby="dzn-booking-success-title" hidden>
                        <p class="dzn-booking__kicker">درخواست ثبت شد</p>
                        <h2 id="dzn-booking-success-title">درخواست شما برای بررسی ارسال شد</h2>
                        <p>این شماره را برای پیگیری نگه دارید:</p>
                        <p class="dzn-booking__reference" data-reference dir="ltr"></p>
                        <p>هنوز زمان جلسه تأیید یا رزرو نشده است. تیم دلنوازان پس از بررسی گزینه‌های زمانی با شما تماس می‌گیرد.</p>
                        <a class="dzn-booking__button" href="<?php echo esc_url( home_url( '/' ) ); ?>">بازگشت به صفحهٔ اصلی</a>
                    </section>
                </section>
            <?php endif; ?>
        </div>
    </main>
    <?php
}

function dzn_theme_enqueue_booking_assets(): void {
    $script_path = get_theme_file_path( 'assets/js/booking.js' );
    $style_path = get_theme_file_path( 'assets/css/booking.css' );
    wp_enqueue_style( 'delnavazan-booking', get_theme_file_uri( 'assets/css/booking.css' ), array( 'delnavazan-theme' ), file_exists( $style_path ) ? (string) filemtime( $style_path ) : wp_get_theme()->get( 'Version' ) );
    wp_enqueue_script( 'delnavazan-booking', get_theme_file_uri( 'assets/js/booking.js' ), array(), file_exists( $script_path ) ? (string) filemtime( $script_path ) : wp_get_theme()->get( 'Version' ), array( 'strategy' => 'defer', 'in_footer' => true ) );
    wp_localize_script( 'delnavazan-booking', 'dznBooking', array( 'apiRoot' => esc_url_raw( rest_url() ), 'options' => dzn_theme_booking_options(), 'privacyVersion' => '2026-09-05' ) );
}
