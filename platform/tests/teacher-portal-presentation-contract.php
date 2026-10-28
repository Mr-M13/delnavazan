<?php
/**
 * Render-level contract for the teacher portal presentation completion.
 *
 * Renders the real theme templates with minimal WordPress stubs and asserts on the produced HTML, so it proves what a teacher
 * actually sees: upcoming-lesson cards, schedule/timezone clarity, honest empty/error/outcome states, accessible controls,
 * preserved backend-authorised actions (booking acceptance, Meet join), and no fake actions or demo data in production.
 */
namespace {
    $root  = dirname(__DIR__, 2);
    $theme = $root . '/theme';
    define('ABSPATH', $root . '/');
    $GLOBALS['dzn_env'] = 'production';

    function add_action(...$a): void {}
    function add_filter(...$a): void {}
    function apply_filters($tag, $value = null, ...$a) { return $value; }
    function esc_html($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
    function esc_attr($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
    function esc_url($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
    function sanitize_key($k): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k)); }
    function sanitize_text_field($v): string { return trim(strip_tags((string) $v)); }
    function wp_unslash($v) { return $v; }
    function wp_generate_uuid4(): string { return '11111111-2222-4333-8444-555555555555'; }
    function wp_get_environment_type(): string { return $GLOBALS['dzn_env']; }
    function is_user_logged_in(): bool { return true; }
    function wp_logout_url($r = ''): string { return 'https://example.invalid/logout'; }
    function wp_login_url($r = ''): string { return 'https://example.invalid/login?redirect_to=' . $r; }
    function home_url($p = ''): string { return 'https://example.invalid' . $p; }
    function add_query_arg($k, $v = null, $u = null): string { return rtrim((string) $u, '/') . '/?' . $k . '=' . $v; }
    function is_page_template($t = ''): bool { return false; }
    function get_queried_object_id(): int { return 0; }
    function timezone_ids(): array { return timezone_identifiers_list(); }
    function get_template_part(string $slug, $name = null, array $args = array()): void {
        $file = ABSPATH . 'theme/' . $slug . '.php';
        (static function () use ($file, $args) { include $file; })();
    }

    require_once $theme . '/inc/teacher-portal.php';

    $failures = array();
    $check = static function (string $name, bool $ok) use (&$failures): void { if (!$ok) { $failures[] = $name; } };
    $render = static function (string $screen, array $model): string {
        ob_start();
        dzn_theme_render_teacher_portal($screen, $model);
        return (string) ob_get_clean();
    };
    $class = static function (array $o = array()): array {
        return array_merge(array(
            'ref' => 'LSN-1', 'state' => 'upcoming', 'time' => '10:00', 'date' => 'Saturday 3 October', 'starts_at_utc' => '2026-10-03 06:30:00', 'starts_at_iso' => '2026-10-03T06:30:00Z', 'timezone' => 'Asia/Tehran',
            'student' => 'Student One', 'course' => 'Setar',
            'progress' => 'Term 1 · 2/12', 'status' => 'پیش رو', 'student_summary' => 'Summary', 'previous_private_note' => 'No private note here',
            'schedule_summary' => 'Saturday 3 October، 10:00 · Asia/Tehran', 'start_available' => false, 'details_available' => true,
            'join_action_url' => 'https://example.invalid/wp-admin/admin-post.php?action=dzn_teacher_join_class', 'join_nonce' => 'nonce-join',
            'lesson_uid' => 'LESSON-UID', 'schedule_version_uid' => 'SCHEDULE-UID', 'confirmation_uid' => '',
            'delivery_issue_action_url' => 'https://example.invalid/wp-admin/admin-post.php?action=dzn_teacher_report_delivery_issue', 'delivery_issue_nonce' => 'nonce-issue',
        ), $o);
    };
    $request = static function (array $o = array()): array {
        return array_merge(array(
            'ref' => 'DELIVERY-1', 'action_available' => true, 'authoritative_request' => true, 'state' => 'intro_request',
            'title' => 'درخواست تازهٔ کلاس آشنایی', 'context' => 'Neda · Setar', 'message' => 'Sunday 4 October، 18:30 به وقت شما · این فقط درخواست در انتظار بررسی است و کلاس تأییدشده نیست.',
            'due' => 'در انتظار پاسخ', 'primary' => 'مشاهدهٔ درخواست',
            'response_action_url' => 'https://example.invalid/wp-admin/admin-post.php?action=dzn_teacher_booking_response', 'response_nonce' => 'nonce-response', 'delivery_uid' => 'DELIVERY-UID',
        ), $o);
    };
    $nav = array(
        array('screen' => 'home', 'label' => 'حساب کاربری', 'url' => 'https://example.invalid/teacher-portal/', 'current' => true),
        array('screen' => 'finance', 'label' => 'مالی', 'url' => 'https://example.invalid/teacher-portal/?teacher-view=finance', 'current' => false),
        array('screen' => 'classes', 'label' => 'کلاس‌ها', 'url' => 'https://example.invalid/teacher-portal/?teacher-view=classes', 'current' => false),
        array('screen' => 'settings', 'label' => 'تنظیمات', 'url' => 'https://example.invalid/teacher-portal/?teacher-view=settings', 'current' => false),
    );
    $model = static function (array $o = array()) use ($nav): array {
        return array_merge(array(
            'available' => true, 'screen' => 'home', 'teacher' => array('first_name' => 'Sara', 'full_name' => 'Sara Example'), 'teacher_timezone' => 'Asia/Tehran', 'navigation' => $nav,
            'announcement' => array('state' => 'none'), 'attention' => array(), 'classes' => array(), 'calendar' => array(),
        ), $o);
    };

    // 1. Upcoming lesson cards: honest heading, visible date + timezone, status text, semantic list, labelled cards.
    $booking = $class(array('ref' => 'BK-1', 'confirmation_uid' => 'CONF-UID', 'details_available' => false, 'start_available' => false, 'join_message' => 'ورود به کلاس از یک ساعت پیش از شروع فعال می‌شود.', 'lesson_uid' => '', 'schedule_version_uid' => '', 'date' => 'Sunday 4 October', 'starts_at_utc' => '2026-10-04 08:30:00', 'schedule_summary' => 'Sunday 4 October، 18:30 · Australia/Brisbane', 'status' => 'کلاس آشنایی تأیید شده', 'progress' => 'کلاس آشنایی'));
    $lesson  = $class(array('start_available' => true, 'state' => 'starting_soon', 'status' => 'به‌زودی شروع می‌شود'));
    $home = $model(array('classes' => array($booking, $lesson), 'calendar' => array(array('day' => 'Sunday 4 October', 'time' => '18:30', 'label' => 'Setar · Neda', 'state' => 'intro'))));
    $check('fixture satisfies the unchanged display contract', dzn_theme_teacher_portal_validate_model($home, 'home'));
    $html = $render('home', $home);
    $check('today and upcoming sections use the required Persian headings', str_contains($html, 'کلاس‌های امروز') && str_contains($html, 'کلاس‌های آینده'));
    $check('home uses the simple welcome header without a Delnavazan brand link', str_contains($html, 'درود بر شما') && !str_contains($html, 'dzn-tp-appbar__brand') && !str_contains($html, 'صفحهٔ اصلی'));
    $check('classes render as an ordered list of labelled articles', substr_count($html, '<ol class="dzn-tp-class-list">') === 1 && substr_count($html, 'aria-labelledby="tp-class-title-') === 2 && str_contains($html, 'id="tp-class-title-0"') && str_contains($html, 'id="tp-class-title-1"'));
    $check('date and timezone are visible on a booking class that has no details panel', str_contains($html, 'Sunday 4 October، 18:30 · Australia/Brisbane') && !str_contains($html, 'id="tp-details-0"'));
    $check('lesson date badge and machine-readable start are rendered', str_contains($html, 'class="dzn-tp-time-date">Sunday 4 October') && str_contains($html, 'class="dzn-tp-time-date">Saturday 3 October') && str_contains($html, 'datetime="2026-10-03T06:30:00Z"'));
    $check('the teacher timezone is labelled on the lessons and calendar sections', substr_count($html, 'class="dzn-timezone-label"') === 2 && str_contains($html, 'منطقهٔ زمانی: Asia/Tehran'));
    $withStats = $render('home', $model(array('statistics' => array('active_students' => '8', 'upcoming' => '2', 'lessons_month' => '10', 'hours_month' => '5'))));
    $check('the home summary labels the teacher timezone', str_contains($withStats, 'class="dzn-timezone-label"') && str_contains($withStats, 'منطقهٔ زمانی: Asia/Tehran'));
    $check('status is exposed as text for assistive technology', str_contains($html, 'وضعیت: </span>کلاس آشنایی تأیید شده'));
    $check('details are collapsed by default and the toggle is wired with aria-controls', str_contains($html, 'id="tp-details-1" class="dzn-tp-class-details" hidden') && str_contains($html, 'aria-expanded="false" aria-controls="tp-details-1"'));

    // 1b. Optional display fields and per-row resilience.
    $optionalNote = $class(array('previous_private_note' => ''));
    $check('an absent optional private note is accepted', dzn_theme_teacher_portal_class_valid($optionalNote));
    $check('optional private note does not weaken the home model', dzn_theme_teacher_portal_validate_model($model(array('classes' => array($optionalNote), 'calendar' => array())), 'home'));
    $malformedClass = $class(array('ref' => ''));
    $mixed = $model(array('classes' => array($malformedClass, $lesson), 'calendar' => array(array('day' => 'Sunday 4 October', 'time' => '18:30', 'label' => 'Setar · Neda', 'state' => 'intro'))));
    $check('one malformed class does not disable the whole teacher home', dzn_theme_teacher_portal_validate_model($mixed, 'home'));
    $mixedHtml = $render('home', $mixed);
    $check('a malformed class is reported safely while healthy classes still render', str_contains($mixedHtml, 'اطلاعات این کلاس کامل یا معتبر نیست') && str_contains($mixedHtml, 'Student One') && substr_count($mixedHtml, 'aria-labelledby="tp-class-title-') === 2);
    $malformedCalendar = $mixed;
    $malformedCalendar['calendar'] = array(array('day' => '', 'time' => '', 'label' => '', 'state' => 'regular'));
    $check('one malformed calendar row does not disable the whole teacher home', dzn_theme_teacher_portal_validate_model($malformedCalendar, 'home'));
    $check('a malformed calendar row is reported safely', str_contains($render('home', $malformedCalendar), 'اطلاعات این رویداد معتبر نیست'));

    // 2. Google Meet join security and ownership: backend-issued URL, nonce and identifiers only, field-for-field.
    $check('lesson join form posts backend URL, nonce, lesson and schedule identifiers', (bool) preg_match('#<form method="post" target="_blank" rel="noopener noreferrer" action="https://example.invalid/wp-admin/admin-post.php\?action=dzn_teacher_join_class"[^>]*><input type="hidden" name="_wpnonce" value="nonce-join"><input type="hidden" name="lesson_uid" value="LESSON-UID"><input type="hidden" name="schedule_version_uid" value="SCHEDULE-UID">#', $html) && !str_contains($html, 'name="confirmation_uid" value=""'));
    $joined = $render('home', $model(array('classes' => array($class(array('start_available' => true, 'confirmation_uid' => 'CONF-UID', 'details_available' => false, 'join_action_url' => 'https://example.invalid/wp-admin/admin-post.php?action=dzn_teacher_join_booking_class', 'join_nonce' => 'nonce-booking-join'))))));
    $check('booking join form posts only the confirmation identifier', str_contains($joined, 'name="confirmation_uid" value="CONF-UID"') && !str_contains($joined, 'name="lesson_uid"') && str_contains($joined, 'value="nonce-booking-join"'));
    $check('join is never offered when the backend has not authorised it', substr_count($html, 'name="_wpnonce" value="nonce-join"') === 1 && !str_contains(substr($html, 0, strpos($html, 'id="tp-class-title-1"')), 'name="_wpnonce" value="nonce-join"'));
    $check('unavailable start is a disabled button with a contextual accessible reason', str_contains($html, 'disabled title="ورود به کلاس از یک ساعت پیش از شروع فعال می‌شود." aria-describedby="tp-join-0"') && str_contains($html, 'id="tp-join-0" class="dzn-tp-sr">ورود به کلاس از یک ساعت پیش از شروع فعال می‌شود.'));
    $generic = $render('home', $model(array('classes' => array($class()))));
    $check('a lesson that cannot start still explains why', str_contains($generic, 'disabled title="زمان ورود نرسیده یا دسترسی امن برای این کلاس تأیید نشده است."') && str_contains($generic, 'id="tp-join-0" class="dzn-tp-sr">'));
    $check('delivery issue report keeps its nonce and identifiers', str_contains($html, 'name="_wpnonce" value="nonce-issue"') && str_contains($html, 'name="lesson_uid" value="LESSON-UID"') && str_contains($html, 'name="schedule_version_uid" value="SCHEDULE-UID"'));
    $check('real forms opt into busy-state handling', substr_count($html, 'data-dzn-tp-submit') >= 2 && str_contains($html, 'data-dzn-tp-busy-label="در حال باز کردن کلاس…"'));

    // 3. Booking acceptance is preserved and gains an honest in-progress state.
    $attn = $render('home', $model(array('attention' => array($request()))));
    $check('accept and decline forms keep nonce, response key, delivery uid and decision', substr_count($attn, 'name="_wpnonce" value="nonce-response"') === 2 && substr_count($attn, 'name="response_key" value="11111111-2222-4333-8444-555555555555"') === 2 && substr_count($attn, 'name="delivery_uid" value="DELIVERY-UID"') === 2 && str_contains($attn, 'name="decision" value="accept"') && str_contains($attn, 'name="decision" value="decline"'));
    $check('request is distinguished from a confirmed class and provider-first rule is stated', str_contains($attn, 'درخواست در انتظار بررسی') && str_contains($attn, 'فقط پس از موفقیت هر دو'));
    $check('acceptance shows progress while Calendar and Meet are created', str_contains($attn, 'data-dzn-tp-busy-label="در حال ساخت رویداد و پیوند Meet… لطفاً صفحه را نبندید"') && str_contains($attn, 'role="status" aria-live="polite"'));
    $check('dialog is announced and labelled', str_contains($attn, 'aria-haspopup="dialog"') && str_contains($attn, 'aria-labelledby="tp-action-title-0"'));
    $inform = $render('home', $model(array('attention' => array($request(array('authoritative_request' => false))))));
    $section = static function (string $html, string $id): string { $start = strpos($html, 'aria-labelledby="' . $id . '"'); $end = strpos($html, '</section>', (int) $start); return (string) substr($html, (int) $start, $end - (int) $start); };
    $informed = $section($inform, 'tp-attention');
    $check('a non-authoritative item offers no action and says so', str_contains($informed, 'dzn-tp-attention-card') && !str_contains($informed, '<dialog') && !str_contains($informed, 'data-dzn-tp-open') && !str_contains($informed, 'name="decision"') && str_contains($informed, 'فقط برای اطلاع نمایش داده می‌شود'));

    // 4. No fake actions or demo data in production, even if a model claims to be a demo.
    $demoModel = $model(array('is_demo' => true, 'attention' => array($request(array('authoritative_request' => false, 'state' => 'student_absence'))), 'classes' => array($class(array('start_available' => true))), 'calendar' => array(array('day' => 'Mon', 'time' => '09:00', 'label' => 'x', 'state' => 'regular'))));
    foreach (array('real model' => $home, 'model flagged demo in production' => $demoModel) as $label => $m) {
        $out = $render('home', $m);
        $check("production render ($label) has no presentation-only controls", !str_contains($out, 'data-dzn-tp-presentation') && !str_contains($out, '<textarea') && !str_contains($out, 'این کنترل فقط پیش‌نمایش') && !str_contains($out, 'روز قبل') && !str_contains($out, 'هفتهٔ بعد') && !str_contains($out, 'درخواست بررسی زمان') && !str_contains($out, 'در آینده اینجا'));
    }
    $GLOBALS['dzn_env'] = 'local';
    $preview = $render('home', $demoModel);
    $check('non-production preview keeps its clearly-labelled preview controls', str_contains($preview, 'data-dzn-tp-presentation') && str_contains($preview, '<textarea') && str_contains($preview, 'پیش‌نمایش نمایشی'));
    $GLOBALS['dzn_env'] = 'production';
    dzn_theme_teacher_portal_context($model());

    // 5. Actionable empty states.
    $empty = $render('home', $model());
    $check('empty classes and calendar explain themselves while empty attention is hidden', str_contains($empty, 'کلاسی برای امروز ثبت نشده است') && str_contains($empty, 'کلاسی برای روزهای آینده ثبت نشده است') && str_contains($empty, 'رویدادی در تقویم پیش رو نیست') && ! str_contains($empty, 'id="tp-attention"') && ! str_contains($empty, 'اکنون کار فوری‌ای ندارید') && 3 === preg_match_all('/class="dzn-tp-empty-state(?:"| )/', $empty));
    $check('empty classes links to real availability settings', str_contains($empty, 'href="https://example.invalid/teacher-portal/?teacher-view=settings">بررسی زمان‌های در دسترس'));

    // 6. Outcome and error states reach the teacher; arbitrary query text never does.
    $_GET = array('dzn_notice' => dzn_theme_teacher_portal_response_notice_allowlist()[3], 'dzn_error' => '0');
    $ok = $render('home', $model());
    $check('allowlisted acceptance outcome is shown as a status', str_contains($ok, 'class="dzn-tp-notice is-success" role="status">' . esc_html(dzn_theme_teacher_portal_response_notice_allowlist()[3])));
    $_GET = array('dzn_notice' => dzn_theme_teacher_portal_response_notice_allowlist()[2], 'dzn_error' => '1');
    $bad = $render('home', $model());
    $check('failed calendar creation is shown as an alert', str_contains($bad, 'role="alert"') && str_contains($bad, 'ساخت تقویم کامل نشد'));
    $_GET = array('dzn_notice' => 'Call 0400 000 000 to claim your prize', 'dzn_error' => '0');
    $spoof = $render('home', $model());
    $check('arbitrary notice text from a crafted link is not rendered', !str_contains($spoof, 'Call 0400') && !str_contains($spoof, 'dzn-tp-notice'));
    $_GET = array('class_status' => 'join_unavailable');
    $join = $render('home', $model());
    $check('join-unavailable redirect is announced as an alert', str_contains($join, 'role="alert">پیوند امن Meet در دسترس نیست'));
    $_GET = array('class_status' => 'delivery_issue_recorded');
    $recorded = $render('home', $model());
    $check('recorded delivery issue is announced as a status', str_contains($recorded, 'role="status">گزارش مشکل برگزاری کلاس ثبت شد.'));
    $_GET = array('dzn_notice' => dzn_theme_teacher_portal_response_notice_allowlist()[0]);
    $check('outcome notices are home-screen only', dzn_theme_teacher_portal_notices('finance') === array());
    $_GET = array();
    $controller = (string) file_get_contents($root . '/platform/src/Portals/TeacherBookingResponseController.php');
    preg_match_all("/'([^']*[\\x{0600}-\\x{06FF}][^']*)'/u", $controller, $found);
    $check('every outcome message the controller can issue is allowlisted', count($found[1]) === 7 && !array_diff($found[1], dzn_theme_teacher_portal_response_notice_allowlist()));

    // 7. Unavailable and error states give a real next step.
    $signedOut = $render('home', array('available' => false, 'state' => 'signed_out', 'screen' => 'home'));
    $check('signed-out state links to sign-in', str_contains($signedOut, 'href="https://example.invalid/login?redirect_to=https://example.invalid/teacher-portal/">ورود به حساب'));
    $error = $render('home', array('available' => false, 'state' => 'error', 'screen' => 'home'));
    $check('error state is an alert with a retry link', str_contains($error, 'role="alert"') && str_contains($error, 'تلاش دوباره'));
    $onboarding = $render('home', array('available' => false, 'state' => 'onboarding_required', 'screen' => 'home'));
    $check('onboarding-required state links to the wizard', str_contains($onboarding, 'teacher-view=onboarding">ادامهٔ شروع همکاری'));
    $notLinked = $render('home', array('available' => false, 'state' => 'not_linked', 'screen' => 'home'));
    $check('not-linked state links to the canonical invitation route instead of inventing an action', str_contains($notLinked, 'href="https://example.invalid/teacher-invitation/">ثبت دعوت‌نامهٔ همکاری') && str_contains($notLinked, 'صفحهٔ اصلی') && !str_contains($notLinked, 'teacher-portal/invitation'));

    // 8. Source guards: boundary, accessibility and assets.
    $parts = array();
    foreach (array('classes', 'attention', 'calendar', 'shell', 'help') as $p) { $parts[$p] = (string) file_get_contents($theme . "/template-parts/teacher-portal/$p.php"); }
    $joined_src = implode("\n", $parts) . (string) file_get_contents($theme . '/inc/teacher-portal.php');
    $check('presentation reads no tables, calls no provider and creates no domain state', !preg_match('/\$wpdb|wp_remote_|curl_|Delnavazan\\\\Platform|platform_outbox|update_option|update_user_meta/', $joined_src));
    $bridge = (string) file_get_contents($theme . '/inc/platform-bridge.php');
    $check('view model supplies locale-aware date, UTC start and one teacher timezone for every lesson', str_contains($bridge, "'date' => dzn_theme_platform_calendar_time") && str_contains($bridge, "'starts_at_utc' => (string)") && str_contains($bridge, "'timezone' => \$class_timezone") && str_contains($bridge, "'teacher_timezone' => \$teacher_timezone") && str_contains($bridge, "dzn_theme_platform_teacher_day_window"));
    $check('canonical term lessons fall back from empty display reference to lesson UID', str_contains($bridge, 'reference_code') && str_contains($bridge, 'lesson_uid') && str_contains($bridge, "if ( '' === \$reference )"));
    $check('shell never echoes an arbitrary dzn_notice query value', !str_contains($parts['shell'], "\$_GET['dzn_notice']") && str_contains($parts['shell'], 'dzn_theme_teacher_portal_notices'));
    $js = (string) file_get_contents($theme . '/assets/js/teacher-portal.js');
    $check('script disables the whole scope after submit, restores on bfcache return, and keeps the server as authority', str_contains($js, 'form[data-dzn-tp-submit]') && str_contains($js, "'pageshow'") && str_contains($js, 'setTimeout(() => scopeButtons(form)') && str_contains($js, 'event.persisted') && !str_contains($js, 'fetch(') && !str_contains($js, 'XMLHttpRequest'));
    $check('toggle swaps its label and keeps aria-expanded in sync', str_contains($js, 'dznTpLabelOpen') && str_contains($js, "setAttribute('aria-expanded'"));
    $css = (string) file_get_contents($theme . '/assets/css/teacher-portal.css');
    $check('stylesheet covers mobile, forced colours, reduced motion and screen-reader text', str_contains($css, 'max-width:35rem') && str_contains($css, 'forced-colors:active') && str_contains($css, 'prefers-reduced-motion') && str_contains($css, '.dzn-tp-sr{'));
    if (trim((string) shell_exec('command -v node 2>/dev/null')) !== '') {
        exec('node --check ' . escapeshellarg($theme . '/assets/js/teacher-portal.js') . ' 2>&1', $out, $code);
        $check('teacher-portal.js parses', 0 === $code);
    }

    if ($failures) { throw new RuntimeException('Teacher portal presentation contract failed: ' . implode('; ', $failures)); }
    echo "Teacher portal presentation contract passed\n";
}
