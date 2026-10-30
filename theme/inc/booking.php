<?php
/**
 * Public booking request experience. Platform remains the data and workflow authority.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function dzn_theme_booking_options(): array {
    static $options = null;
    if ( is_array( $options ) ) { return $options; }
    $class = '\Delnavazan\Platform\Core\Application\PublicBookingOptionsReadService';
    if ( ! class_exists( $class ) ) { return $options = array(); }
    try { return $options = ( new $class() )->introductoryInstruments(); } catch ( Throwable $e ) { return $options = array(); }
}

/** Normalise Persian instrument labels for matching across the static belt and the canonical catalogue. */
function dzn_theme_booking_normalize_instrument_name( string $name ): string {
    $name = str_replace( array( "\xE2\x80\x8C", 'ي', 'ك' ), array( ' ', 'ی', 'ک' ), $name );
    $normalised = preg_replace( '/\s+/u', ' ', trim( $name ) );
    $normalised = is_string( $normalised ) ? $normalised : '';
    return 'تنبک' === $normalised ? 'تمبک' : $normalised;
}

/**
 * Build the canonical enrolment URL for one homepage instrument tile.
 *
 * The Platform catalogue slug is authoritative; the static tile label is only a
 * lookup key. Returning the catalogue slug here means the enrolment page selects
 * the same instrument even when the Persian display labels differ (for example
 * «سه‌تار» vs «سه تار» or «تنبک» vs «تمبک»).
 */
function dzn_theme_booking_enrol_url( string $name, string $fallback_slug = '' ): string {
    $url = home_url( '/enrol/' );
    $target = dzn_theme_booking_normalize_instrument_name( $name );
    $aliases = array( 'تنبک' => 'تمبک' );
    if ( isset( $aliases[ $target ] ) ) { $target = $aliases[ $target ]; }
    foreach ( dzn_theme_booking_options() as $option ) {
        if ( ! is_array( $option ) ) { continue; }
        $slug = (string) ( $option['slug'] ?? '' );
        $name_fa = (string) ( $option['name_fa'] ?? '' );
        $name_en = (string) ( $option['name_en'] ?? '' );
        $matches_name = '' !== $target && ( $target === dzn_theme_booking_normalize_instrument_name( $name_fa ) || $target === dzn_theme_booking_normalize_instrument_name( $name_en ) );
        $matches_slug = '' !== $fallback_slug && $fallback_slug === $slug;
        if ( '' !== $slug && ( $matches_name || $matches_slug ) ) {
            // Match by the catalogue's exact Persian name. This avoids
            // double-encoding the URL-encoded Persian slug while still letting
            // the enrolment page select the authoritative instrument option.
            return add_query_arg(
                array( 'instrument_name' => '' !== $name_fa ? $name_fa : $name ),
                $url
            );
        }
    }
    return '' !== $fallback_slug
        ? add_query_arg( array( 'instrument' => $fallback_slug, 'instrument_name' => $name ), $url )
        : $url;
}

function dzn_theme_render_booking_route(): void {
    // This page embeds a per-render request token, so it must never be served
    // from a shared cache. The cookie is HttpOnly; JavaScript receives only the
    // matching signed value injected below.
    nocache_headers();
    $booking_csrf = dzn_theme_booking_csrf_token();
    $options = dzn_theme_booking_options();
    $belt_order = array( 'تار', 'تمبک', 'سه‌تار', 'پیانو', 'سنتور', 'کمانچه', 'دف', 'آواز', 'تنبور', 'نی', 'ویولن', 'گیتار' );
    $belt_rank = array();
    foreach ( $belt_order as $rank => $label ) {
        $belt_rank[ dzn_theme_booking_normalize_instrument_name( $label ) ] = $rank;
    }
    usort( $options, static function ( $a, $b ) use ( $belt_rank ) {
        $a_rank = $belt_rank[ dzn_theme_booking_normalize_instrument_name( (string) ( $a['name_fa'] ?? '' ) ) ] ?? PHP_INT_MAX;
        $b_rank = $belt_rank[ dzn_theme_booking_normalize_instrument_name( (string) ( $b['name_fa'] ?? '' ) ) ] ?? PHP_INT_MAX;
        return $a_rank <=> $b_rank;
    } );
    $default_timezone = wp_timezone_string();
    if ( ! $default_timezone || ! in_array( $default_timezone, timezone_identifiers_list(), true ) ) { $default_timezone = 'UTC'; }
    $requested = isset( $_GET['instrument'] ) && is_string( $_GET['instrument'] ) ? sanitize_text_field( wp_unslash( $_GET['instrument'] ) ) : '';
    $requested_name = isset( $_GET['instrument_name'] ) && is_string( $_GET['instrument_name'] ) ? sanitize_text_field( wp_unslash( $_GET['instrument_name'] ) ) : '';
    $selected = null;
    $booking_user = is_user_logged_in() ? wp_get_current_user() : null;
    $booking_profile = array();
    if ( $booking_user && function_exists( 'dzn_theme_platform_student_read' ) ) {
        $student_read = dzn_theme_platform_student_read();
        if ( 'ok' === ( $student_read['state'] ?? '' ) && is_array( $student_read['data']['profile'] ?? null ) ) { $booking_profile = $student_read['data']['profile']; }
    }
    $booking_name = trim( (string) ( $booking_profile['full_name'] ?? ( $booking_user ? $booking_user->display_name : '' ) ) );
    $booking_email = trim( (string) ( $booking_profile['email'] ?? ( $booking_user ? $booking_user->user_email : '' ) ) );
    $booking_country = strtoupper( (string) ( $booking_profile['country_code'] ?? '' ) );
    $booking_city = (string) ( $booking_profile['city'] ?? '' );
    $booking_mobile = (string) ( $booking_profile['mobile'] ?? '' );
    $booking_timezone = (string) ( $booking_profile['timezone'] ?? '' );
    if ( ! in_array( $booking_timezone, timezone_identifiers_list(), true ) ) { $booking_timezone = ''; }
    foreach ( $options as $option ) {
        if ( ( $requested !== '' && $requested === (string) $option['slug'] ) || ( $requested_name !== '' && ( $requested_name === (string) $option['name_fa'] || $requested_name === (string) $option['name_en'] ) ) ) { $selected = $option; break; }
    }
    ?>
    <main id="main-content" class="site-main dzn-booking" tabindex="-1">
        <div class="dzn-container dzn-booking__container">
            <header class="dzn-booking__heading">
                <p class="dzn-eyebrow">شروع مسیر موسیقی شما</p>
                <h1>درخواست جلسهٔ معارفه</h1>
                <p>پس از انتخاب دورهٔ آموزشی و زمان‌های پیشنهادی، تیم دلنوازان درخواست شما را بررسی می‌کند، مدرس کلاس را هماهنگ می‌کند و نتیجه را به شما اطلاع می‌دهد.</p>
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
                            <li class="is-current" data-progress="instrument"><button type="button" data-progress-target="instrument"><span>۱</span>انتخاب دوره</button></li>
                            <li data-progress="availability"><button type="button" data-progress-target="availability"><span>۲</span>انتخاب زمان</button></li>
                            <li data-progress="contact"><button type="button" data-progress-target="contact"><span>۳</span>اطلاعات کاربری</button></li>
                        </ol>
                    </nav>
                    <div class="dzn-booking__error" data-error role="alert" hidden></div>
                    <section class="dzn-booking__step is-active" data-step="instrument" aria-labelledby="dzn-booking-instrument-title">
                        <p class="dzn-booking__kicker">مرحلهٔ اول</p>
                        <h2 id="dzn-booking-instrument-title" tabindex="-1">دورهٔ آموزشی خود را انتخاب کنید</h2>
                        <div class="dzn-booking__instrument-grid" role="group" aria-label="انتخاب ساز">
                            <?php foreach ( $options as $option ) : ?>
                                <button class="dzn-booking__instrument<?php echo $selected && (int) $selected['id'] === (int) $option['id'] ? ' is-selected' : ''; ?>" type="button" data-instrument-choice="<?php echo esc_attr( (string) $option['id'] ); ?>" aria-pressed="<?php echo $selected && (int) $selected['id'] === (int) $option['id'] ? 'true' : 'false'; ?>">
                                    <?php $instrument_image = dzn_theme_instrument_image( (string) $option['name_fa'] ); if ( '' === $instrument_image ) { $instrument_image = dzn_theme_instrument_image( (string) $option['name_en'] ); } if ( '' === $instrument_image ) { $instrument_image = dzn_theme_instrument_image( (string) $option['slug'] ); } if ( '' !== $instrument_image ) : ?><img class="dzn-booking__instrument-image" src="<?php echo esc_url( get_theme_file_uri( 'assets/images/' . $instrument_image ) ); ?>" alt=""><?php else : ?><span class="dzn-booking__instrument-mark" aria-hidden="true">♫</span><?php endif; ?>
                                    <span class="dzn-booking__instrument-name"><?php echo esc_html( $option['name_fa'] ?: $option['name_en'] ); ?></span>
                                    <span class="dzn-booking__instrument-action">انتخاب دوره</span>
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
                        <p class="dzn-booking__help"><?php echo esc_html( function_exists( 'dzn_theme_introductory_pricing_note' ) ? dzn_theme_introductory_pricing_note() : 'جلسهٔ معارفه' ); ?>؛ انتخاب ساز هنوز زمان کلاس را رزرو نمی‌کند.</p>
                        <div class="dzn-booking__actions">
                            <a class="dzn-booking__back" href="<?php echo esc_url( home_url( '/' ) ); ?>">بازگشت به صفحهٔ اصلی</a>
                            <button class="dzn-booking__button" type="button" data-next="availability">انتخاب زمان</button>
                        </div>
                    </section>
                    <section class="dzn-booking__step" data-step="availability" aria-labelledby="dzn-booking-availability-title" hidden>
                        <p class="dzn-booking__kicker">مرحلهٔ دوم · زمان جلسه</p>
                        <h2 id="dzn-booking-availability-title" tabindex="-1">چه زمانی برای شما مناسب است؟</h2>
                        <div class="dzn-booking__calendar">
                            <div class="dzn-booking__calendar-heading">
                                <button type="button" class="dzn-booking__calendar-nav" data-calendar-prev aria-label="ماه قبل">‹</button>
                                <h3 data-calendar-label aria-live="polite"></h3>
                                <button type="button" class="dzn-booking__calendar-nav" data-calendar-next aria-label="ماه بعد">›</button>
                            </div>
                            <div class="dzn-booking__calendar-grid" data-calendar-weekdays aria-hidden="true"></div>
                            <div class="dzn-booking__calendar-grid" data-calendar role="group" aria-label="انتخاب روز" tabindex="-1"></div>
                        </div>
                        <p class="dzn-booking__time-guidance">اگر زمان موردنظر شما در این فهرست نیست، یکی از زمان‌های موجود را برای جلسهٔ معارفه انتخاب کنید و نیازهای زمانی خود را با مدرس در میان بگذارید. هیچ پرداختی در این مرحله انجام نمی‌شود.</p>
                        <section class="dzn-booking__day-times" data-day-times hidden aria-labelledby="dzn-booking-day-title">
                            <h3 id="dzn-booking-day-title" data-day-title>زمان‌های پیشنهادی</h3>
                                                        <div class="dzn-booking__time-options" data-time-options></div>
                        </section>
                        <div class="dzn-booking__preference-heading">
                            <h3>اولویت‌های شما</h3><span data-preference-count>۰ از ۳</span>
                        </div>
                        <ul class="dzn-booking__times" data-times aria-live="polite"></ul>
                        <div class="dzn-booking__availability-key" aria-label="راهنمای وضعیت پیشنهادی زمان‌ها">
                            <span><i class="is-strong"></i>تناسب زمانی خوب</span>
                            <span><i class="is-possible"></i>امکان محدود یا احتمالی</span>
                            <span><i class="is-none"></i>هنوز استادی منطبق نیست؛ قابل درخواست</span>
                        </div>
                        <div class="dzn-booking__actions">
                            <button class="dzn-booking__button dzn-booking__button--secondary" type="button" data-back="instrument">بازگشت به انتخاب ساز</button>
                            <button class="dzn-booking__button" type="button" data-next="contact">ادامه به اطلاعات شما</button>
                        </div>
                    </section>
                    <section class="dzn-booking__step" data-step="contact" aria-labelledby="dzn-booking-contact-title" hidden>
                        <p class="dzn-booking__kicker">مرحلهٔ سوم</p>
                        <h2 id="dzn-booking-contact-title" tabindex="-1">اطلاعات کاربری خود را وارد کنید</h2>
                        <div class="dzn-booking__fields">
                            <div><label for="dzn-booking-name">نام کامل</label><input id="dzn-booking-name" data-contact="full_name" autocomplete="name" maxlength="191" value="<?php echo esc_attr( $booking_name ); ?>" required></div>
                            <div><label for="dzn-booking-email">ایمیل</label><input id="dzn-booking-email" data-contact="email" type="email" autocomplete="email" maxlength="191" value="<?php echo esc_attr( $booking_email ); ?>" <?php echo $booking_user ? 'readonly' : ''; ?> required><p class="dzn-booking__field-help"><?php echo $booking_user ? 'این درخواست به حساب واردشده و پروندهٔ هنرجویی همان حساب متصل می‌شود.' : 'با این ایمیل یک حساب هنرجویی ساخته می‌شود؛ اگر از قبل حساب دارید، رمز همان حساب ورود شما را تأیید می‌کند.'; ?></p></div>
                            <?php if ( ! $booking_user ) : ?>
                            <div><label for="dzn-booking-password">رمز حساب</label><input id="dzn-booking-password" data-account-password type="password" autocomplete="new-password" minlength="12" maxlength="4096" required><p class="dzn-booking__field-help">حداقل ۱۲ نویسه. رمز فقط برای ساخت یا ورود امن به حساب به WordPress فرستاده می‌شود و در درخواست رزرو ذخیره نمی‌شود.</p></div>
                            <div><label for="dzn-booking-password-confirmation">تکرار رمز حساب</label><input id="dzn-booking-password-confirmation" data-account-password-confirmation type="password" autocomplete="new-password" minlength="12" maxlength="4096" required></div>
                            <?php endif; ?>
                            <div><label for="dzn-booking-country">کشور محل زندگی</label><select id="dzn-booking-country" data-contact="country" autocomplete="country" required>
                                <option value="">انتخاب کشور</option>
                                <option value="AU" <?php selected( $booking_country, 'AU' ); ?>>استرالیا</option><option value="BR" <?php selected( $booking_country, 'BR' ); ?>>برزیل</option><option value="CA" <?php selected( $booking_country, 'CA' ); ?>>کانادا</option><option value="FR" <?php selected( $booking_country, 'FR' ); ?>>فرانسه</option><option value="DE" <?php selected( $booking_country, 'DE' ); ?>>آلمان</option><option value="IR" <?php selected( $booking_country, 'IR' ); ?>>ایران</option><option value="NZ" <?php selected( $booking_country, 'NZ' ); ?>>نیوزیلند</option><option value="SE" <?php selected( $booking_country, 'SE' ); ?>>سوئد</option><option value="TR" <?php selected( $booking_country, 'TR' ); ?>>ترکیه</option><option value="AE" <?php selected( $booking_country, 'AE' ); ?>>امارات متحدهٔ عربی</option><option value="GB" <?php selected( $booking_country, 'GB' ); ?>>بریتانیا</option><option value="US" <?php selected( $booking_country, 'US' ); ?>>ایالات متحده</option>
                            </select></div>
                            <div><label for="dzn-booking-city">شهر محل زندگی</label><input id="dzn-booking-city" data-contact="city" autocomplete="address-level2" list="dzn-booking-city-list" maxlength="191" value="<?php echo esc_attr( $booking_city ); ?>" required><datalist id="dzn-booking-city-list" data-city-list></datalist></div>
                            <div class="dzn-booking__field--wide"><label for="dzn-booking-mobile">شمارهٔ موبایل</label><input id="dzn-booking-mobile" data-contact="mobile" type="tel" autocomplete="tel" placeholder="+61 ..." maxlength="32" value="<?php echo esc_attr( $booking_mobile ); ?>" required><p class="dzn-booking__field-help" data-phone-hint>پیش‌شمارهٔ بین‌المللی از کشور شما پیشنهاد می‌شود و قابل ویرایش است.</p></div>
                        <div class="dzn-booking__timezone dzn-booking__field--wide" data-timezone-note>
                            <strong>منطقهٔ زمانی انتخاب‌شده</strong><span class="dzn-booking__timezone-copy">همهٔ تاریخ‌ها و ساعت‌های درخواست بر اساس این منطقهٔ زمانی نمایش داده می‌شوند.</span>
                            <label for="dzn-booking-timezone">منطقهٔ زمانی</label>
                            <select id="dzn-booking-timezone" data-timezone<?php echo $booking_timezone ? ' data-prefilled="1"' : ''; ?> required>
                                <optgroup label="مناطق پرکاربرد دلنوازان">
                                    <option value="Australia/Brisbane" <?php selected( $booking_timezone, 'Australia/Brisbane' ); ?>>استرالیا — بریزبن</option>
                                    <option value="Australia/Sydney">استرالیا — سیدنی / ملبورن</option>
                                    <option value="Pacific/Auckland">نیوزیلند — اوکلند</option>
                                    <option value="America/Los_Angeles">آمریکا / کانادا — غرب</option>
                                    <option value="America/New_York">آمریکا / کانادا — شرق</option>
                                    <option value="Europe/London">بریتانیا — لندن</option>
                                    <option value="Europe/Berlin">اروپای مرکزی</option>
                                    <option value="Asia/Dubai">امارات — دبی</option>
                                    <option value="Asia/Tehran">ایران — تهران</option>
                                </optgroup>
                                <optgroup label="همهٔ مناطق زمانی">
                                <?php echo wp_timezone_choice( $booking_timezone ?: $default_timezone, get_user_locale() ); ?>
                                </optgroup>
                            </select>
                            <p>منطقهٔ زمانی دستگاه شما در صورت شناسایی به‌طور خودکار انتخاب می‌شود؛ در صورت نیاز می‌توانید آن را تغییر دهید.</p>
                        </div>
                        </div>
                        <p class="dzn-booking__whatsapp-note">شمارهٔ موبایل شما برای تماس و هماهنگی این درخواست استفاده می‌شود. اعلان‌های واتساپ یا پیامک فقط در صورت فعال بودن آن کانال ارسال می‌شوند.</p>
                        <label class="dzn-booking__privacy"><input type="checkbox" data-privacy required> موافقم اطلاعات تماس و زمان‌های پیشنهادی من برای بررسی این درخواست در دلنوازان ثبت و استفاده شود. درخواست ثبت‌شده تا ۲۴ ماه نگهداری می‌شود.</label>
                        <p class="dzn-booking__help dzn-booking__policy-links">پیش از ثبت درخواست، <a href="<?php echo esc_url( home_url( '/privacy-policy/' ) ); ?>">سیاست حفظ حریم خصوصی</a>، <a href="<?php echo esc_url( home_url( '/terms-and-conditions/' ) ); ?>">شرایط و ضوابط استفاده</a> و <a href="<?php echo esc_url( home_url( '/cancellation-and-refunds/' ) ); ?>">سیاست لغو و بازپرداخت</a> را بخوانید. این درخواست رایگان است و پرداختی در این مرحله انجام نمی‌شود.</p>
                        <label class="dzn-booking__privacy"><input type="checkbox" data-adult-attested required> تأیید می‌کنم هنرجو ۱۸ سال یا بیشتر دارد و می‌تواند به‌طور مستقل درخواست ثبت‌نام را بپذیرد. برای هنرجوی زیر ۱۸ سال، لطفاً پیش از ثبت درخواست با پشتیبانی تماس بگیرید.</label>
                        <div class="dzn-booking__actions">
                            <button class="dzn-booking__button dzn-booking__button--secondary" type="button" data-back="availability">بازگشت به انتخاب زمان</button>
                            <button class="dzn-booking__button" type="button" data-next="review">مرور و تأیید درخواست</button>
                        </div>
                    </section>

                    <section class="dzn-booking__step" data-step="review" aria-labelledby="dzn-booking-review-title" hidden>
                        <p class="dzn-booking__kicker">بازبینی نهایی</p>
                        <h2 id="dzn-booking-review-title" tabindex="-1">خلاصهٔ درخواست شما</h2>
                        <div class="dzn-booking__inline-review">
                            <h3>خلاصهٔ درخواست</h3><div data-review class="dzn-booking__review"></div>
                            <aside class="dzn-booking__notice dzn-booking__summary-note">
                                <h4><?php echo esc_html( function_exists( 'dzn_theme_introductory_pricing_note' ) ? dzn_theme_introductory_pricing_note() : 'جلسهٔ معارفه' ); ?></h4>
                                <ul>
                                    <li>در این مرحله پرداختی انجام نمی‌شود؛ جزئیات پرداخت پس از تأیید استاد اعلام می‌شود.</li>
                                    <li>زمان‌های انتخابی اولویت شما هستند و پس از بررسی استاد تأیید می‌شوند.</li>
                                    <li>نتیجهٔ هماهنگی از طریق اطلاعات تماسی که وارد کرده‌اید با شما در میان گذاشته می‌شود.</li>
                                    <li>در صورت ادامهٔ دوره، برنامه و شهریهٔ کلاس‌های منظم پس از جلسهٔ معارفه هماهنگ می‌شود.</li>
                                </ul>
                                <p class="dzn-booking__help">اسناد مرتبط: <a href="<?php echo esc_url( home_url( '/terms-and-conditions/' ) ); ?>">شرایط و ضوابط</a> · <a href="<?php echo esc_url( home_url( '/privacy-policy/' ) ); ?>">حریم خصوصی</a> · <a href="<?php echo esc_url( home_url( '/cancellation-and-refunds/' ) ); ?>">لغو و بازپرداخت</a> · <a href="<?php echo esc_url( home_url( '/recording-and-ai-policy/' ) ); ?>">ضبط و هوش مصنوعی</a></p>
                            </aside>
                            <aside class="dzn-booking__notice" role="note">
                                <h4>Recording and research · ضبط و پژوهش</h4>
                                <p lang="en" dir="ltr">Recording and research export are currently disabled. Read the public information page to understand the planned capability and how choices work after an adult student account is linked.</p>
                                <p lang="fa" dir="rtl">ضبط و خروجی پژوهش در حال حاضر غیرفعال است. برای آشنایی با قابلیت برنامه‌ریزی‌شده و نحوهٔ انتخاب‌ها پس از اتصال حساب هنرجوی بزرگسال، صفحهٔ اطلاعات عمومی را بخوانید.</p>
                                <p><a href="<?php echo esc_url( home_url( '/recording-and-research/' ) ); ?>">Recording and research information · اطلاعات ضبط و پژوهش</a></p>
                            </aside>
                        </div>
                        <div class="dzn-booking__actions">
                            <button class="dzn-booking__button dzn-booking__button--secondary" type="button" data-edit-details>ویرایش اطلاعات</button>
                            <button class="dzn-booking__button" type="button" data-submit>ثبت درخواست جلسهٔ معارفه</button>
                        </div>
                    </section>
                    <section class="dzn-booking__step dzn-booking__success" data-step="success" aria-labelledby="dzn-booking-success-title" hidden>
                        <p class="dzn-booking__kicker">درخواست ثبت شد</p>
                        <h2 id="dzn-booking-success-title" tabindex="-1">درخواست شما برای بررسی ارسال شد</h2>
                        <p>این شماره را برای پیگیری نگه دارید:</p>
                        <p class="dzn-booking__reference" data-reference dir="ltr"></p>
                        <p>زمان‌های پیشنهادی شما:</p>
                        <ul class="dzn-booking__success-times" data-success-times></ul>
                        <p>هنوز زمان جلسه تأیید یا رزرو نشده است. درخواست در حساب هنرجویی شما با وضعیت در انتظار بررسی نمایش داده می‌شود.</p>
                        <a class="dzn-booking__button" data-dashboard-link href="<?php echo esc_url( home_url( '/student-portal/' ) ); ?>">رفتن به داشبورد هنرجو</a>
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
    wp_localize_script( 'delnavazan-booking', 'dznBooking', array( 'apiRoot' => esc_url_raw( rest_url() ), 'privacyVersion' => '2026-09-05', 'restNonce' => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '', 'csrfToken' => dzn_theme_booking_csrf_token() ) );
}

function dzn_theme_booking_csrf_token(): string {
    static $token = null;
    if ( is_string( $token ) ) { return $token; }
    $controller = '\\Delnavazan\\Platform\\Public\\BookingRequestRestController';
    if ( ! class_exists( $controller ) || headers_sent() ) { return $token = ''; }
    $token = $controller::issueCsrfToken();
    setcookie( 'dzn_booking_csrf', $token, array(
        'expires' => 0,
        'path' => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
        'domain' => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
        'secure' => is_ssl(),
        'httponly' => true,
        'samesite' => 'Lax',
    ) );
    $_COOKIE['dzn_booking_csrf'] = $token;
    return $token;
}
