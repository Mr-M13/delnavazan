<?php
/**
 * Source contract for Teacher Portal Presentation Completion.
 *
 * Asserts upcoming lesson cards, class detail presentation, schedule and timezone
 * clarity, lesson status, mobile responsiveness, accessibility, and actionable
 * loading/empty/error states while preserving backend-authorised actions and security.
 */

$root = dirname(__DIR__);
$theme = dirname($root) . '/theme';
$read = static fn(string $path): string => (string) file_get_contents($path);

$files = array(
    'bridge' => $read($theme . '/inc/platform-bridge.php'),
    'portal' => $read($theme . '/inc/teacher-portal.php'),
    'shell' => $read($theme . '/template-parts/teacher-portal/shell.php'),
    'home' => $read($theme . '/template-parts/teacher-portal/home.php'),
    'classes' => $read($theme . '/template-parts/teacher-portal/classes.php'),
    'attention' => $read($theme . '/template-parts/teacher-portal/attention.php'),
    'calendar' => $read($theme . '/template-parts/teacher-portal/calendar.php'),
    'account' => $read($theme . '/template-parts/teacher-portal/account.php'),
    'css' => $read($theme . '/assets/css/teacher-portal.css'),
    'js' => $read($theme . '/assets/js/teacher-portal.js'),
);

$checks = array(
    'classes view-model provides date and timezone along with existing contract keys' =>
        str_contains($files['bridge'], "'date' => dzn_theme_platform_zoned_time") &&
        str_contains($files['bridge'], "'timezone' => \$class_timezone") &&
        str_contains($files['bridge'], "'teacher_timezone' =>") &&
        str_contains($files['bridge'], "'start_available'") &&
        str_contains($files['bridge'], "'details_available' => ! \$is_booking") &&
        str_contains($files['bridge'], "'confirmation_uid'") &&
        str_contains($files['bridge'], "'schedule_summary'"),

    'upcoming lesson cards present clear time, date badge, and semantic datetime' =>
        str_contains($files['classes'], 'dzn-tp-class__time') &&
        str_contains($files['classes'], 'dzn-tp-time-clock') &&
        str_contains($files['classes'], 'dzn-tp-time-date') &&
        str_contains($files['classes'], 'datetime="<?php echo esc_attr'),

    'upcoming lesson cards render prominent lesson status badges across approved states' =>
        str_contains($files['classes'], 'dzn-tp-state--<?php echo esc_attr( $state ); ?>') &&
        str_contains($files['classes'], 'dzn-tp-state__bullet') &&
        str_contains($files['css'], '.dzn-tp-state--starting_soon') &&
        str_contains($files['css'], '.dzn-tp-state--upcoming') &&
        str_contains($files['css'], '.dzn-tp-state--student_absence') &&
        str_contains($files['css'], '.dzn-tp-state--replacement') &&
        str_contains($files['css'], '.dzn-tp-state--intro') &&
        str_contains($files['css'], '.dzn-tp-state--flexible'),

    'schedule and timezone clarity is surfaced across headings, summaries, and cards' =>
        str_contains($files['classes'], 'dzn-timezone-label') &&
        str_contains($files['classes'], 'dzn-tp-class__schedule-summary') &&
        str_contains($files['calendar'], 'dzn-timezone-label') &&
        str_contains($files['home'], 'dzn-timezone-label'),

    'Google Meet join action preserves working booking and canonical lesson security' =>
        str_contains($files['bridge'], 'dzn_teacher_join_booking_class') &&
        str_contains($files['bridge'], 'dzn_teacher_join_class') &&
        str_contains($files['classes'], 'join_action_url') &&
        str_contains($files['classes'], 'confirmation_uid') &&
        str_contains($files['classes'], 'lesson_uid') &&
        str_contains($files['classes'], 'schedule_version_uid') &&
        str_contains($files['classes'], 'dzn-tp-btn-join') &&
        str_contains($files['classes'], 'tp-join-') &&
        str_contains($files['classes'], 'join_nonce'),

    'class detail presentation exposes authorized delivery issue reporting and honest guidance' =>
        str_contains($files['classes'], 'dzn-tp-class-details') &&
        str_contains($files['classes'], 'dzn-tp-detail-card') &&
        str_contains($files['classes'], 'delivery_issue_action_url') &&
        str_contains($files['bridge'], 'dzn_teacher_report_delivery_issue') &&
        str_contains($files['classes'], 'گزارش مشکل در برگزاری کلاس') &&
        str_contains($files['classes'], 'previous_private_note'),

    'actionable empty state is provided for classes, attention, and calendar' =>
        str_contains($files['classes'], 'dzn-tp-empty-state') &&
        str_contains($files['classes'], 'کلاس پیش‌رویی در برنامه نیست') &&
        str_contains($files['classes'], 'teacher-view\', \'settings') &&
        str_contains($files['attention'], 'dzn-tp-empty-state') &&
        str_contains($files['attention'], 'همهٔ کارها به‌روز است') &&
        str_contains($files['calendar'], 'dzn-tp-empty-state'),

    'actionable unavailable states in shell provide dedicated CTA paths' =>
        str_contains($files['shell'], 'dzn-tp-unavailable') &&
        str_contains($files['shell'], 'wp_login_url') &&
        str_contains($files['shell'], 'onboarding') &&
        str_contains($files['shell'], 'invitation') &&
        str_contains($files['shell'], 'تلاش دوباره'),

    'shell surfaces flash notifications with accessible status and alert roles' =>
        str_contains($files['shell'], 'dzn_notice') &&
        str_contains($files['shell'], 'dzn-tp-notice') &&
        str_contains($files['shell'], '$is_notice_error ? \'alert\' : \'status\''),

    'working booking acceptance is preserved byte-accurately' =>
        str_contains($files['bridge'], 'dzn_teacher_booking_response') &&
        str_contains($files['attention'], 'response_action_url') &&
        str_contains($files['attention'], 'response_nonce') &&
        str_contains($files['attention'], 'delivery_uid') &&
        str_contains($files['attention'], 'response_key') &&
        str_contains($files['attention'], 'value="accept"') &&
        str_contains($files['attention'], 'value="decline"') &&
        str_contains($files['attention'], 'درخواست در انتظار بررسی') &&
        str_contains($files['attention'], 'فقط پس از موفقیت هر دو'),

    'mobile responsiveness rules and touch targets are enforced' =>
        str_contains($files['css'], '@media(min-width:48rem)') &&
        str_contains($files['css'], '@media(max-width:35rem)') &&
        str_contains($files['css'], 'min-block-size:2.75rem') &&
        str_contains($files['css'], 'max-inline-size:100%'),

    'accessibility attributes are applied across all interactive controls' =>
        str_contains($files['classes'], 'aria-expanded') &&
        str_contains($files['classes'], 'aria-controls') &&
        str_contains($files['classes'], 'aria-describedby') &&
        str_contains($files['classes'], 'aria-labelledby') &&
        str_contains($files['attention'], 'aria-modal="true"') &&
        str_contains($files['css'], ':focus-visible'),
);

foreach ($checks as $name => $ok) {
    if (!$ok) {
        throw new RuntimeException('Teacher portal presentation contract failed: ' . $name);
    }
}

echo 'Teacher portal presentation contracts passed (' . count($checks) . ").\n";
