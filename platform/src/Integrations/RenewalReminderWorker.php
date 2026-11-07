<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Application\PortalNotificationRecorder;
use Delnavazan\Platform\Integrations\Notifications\DelnavazanEmailRenderer;
use Delnavazan\Platform\Integrations\Notifications\NotificationCatalogue;
use Delnavazan\Platform\Integrations\Notifications\NotificationCopyResolver;

/**
 * Term-renewal reminder.
 *
 * A canonical Term has twelve sessions. When eight standard sessions are completed the Student is
 * four sessions from the end of the Term and is reminded to renew from the payments destination of
 * the Student Portal. The reminder is idempotent per Term and never changes a Term, Lesson,
 * obligation or renewal decision.
 *
 * Delivery is best-effort and fail-soft: the portal inbox row is recorded first, then the email is
 * handed to the existing transport. A transport failure never blocks the worker or any business fact.
 */
final class RenewalReminderWorker {
    public const HOOK = 'dzn_renewal_reminder_dispatch';
    public const EVENT_KEY = 'renewal.reminder.student';
    public const SOURCE_KIND = 'renewal_reminder';
    private const BATCH = 50;

    public static function register(): void {
        add_action(self::HOOK, array(__CLASS__, 'run'));
        add_action('init', array(__CLASS__, 'ensureScheduled'));
    }

    public static function ensureScheduled(): void {
        if (!self::schedulingEnabled()) return;
        if (!wp_next_scheduled(self::HOOK)) wp_schedule_event(time() + 300, 'daily', self::HOOK);
    }

    /**
     * @return array{examined:int,sent:int,skipped:int,errors:int}
     */
    public static function run(): array {
        global $wpdb;
        $result = array('examined' => 0, 'sent' => 0, 'skipped' => 0, 'errors' => 0);
        if (!is_object($wpdb)) return $result;
        $prefix = $wpdb->prefix . 'dzn_';
        $now = gmdate('Y-m-d H:i:s');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM (
                SELECT term.id term_id,term.lesson_allocation,enrolment.id enrolment_id,enrolment.student_id,
                       student.display_name,student.email,student.locale,student.calendar_preference,student.timezone,
                       course.name_fa course_name_fa,course.name_en course_name_en,
                       teacher.display_name teacher_display_name,teacher.english_name teacher_english_name,teacher.persian_name teacher_persian_name,
                       (SELECT COUNT(*) FROM {$prefix}lessons lesson
                          WHERE lesson.term_id=term.id AND lesson.lesson_type='standard'
                            AND lesson.status='completed' AND lesson.archived_at IS NULL) completed,
                       (SELECT offer.plan_kind FROM {$prefix}commercial_term_funding_plans plan
                          JOIN {$prefix}commercial_offers offer ON offer.id=plan.offer_id
                         WHERE plan.term_id=term.id ORDER BY plan.id DESC LIMIT 1) plan_kind,
                       (SELECT COUNT(*) FROM {$prefix}commercial_term_funding_plans plan
                          JOIN {$prefix}commercial_offers offer ON offer.id=plan.offer_id
                          JOIN {$prefix}commercial_offer_obligations obligation ON obligation.offer_id=offer.id
                         WHERE plan.term_id=term.id) total_instalments,
                       (SELECT COUNT(*) FROM {$prefix}commercial_term_funding_plans plan
                          JOIN {$prefix}commercial_offers offer ON offer.id=plan.offer_id
                          JOIN {$prefix}commercial_offer_obligations obligation ON obligation.offer_id=offer.id
                          JOIN {$prefix}commercial_obligation_settlements settlement ON settlement.obligation_id=obligation.id
                         WHERE plan.term_id=term.id) paid_instalments,
                       COALESCE(term.ends_at,(
                          SELECT MAX(version.ends_at_utc) FROM {$prefix}lessons last_lesson
                          JOIN {$prefix}canonical_lesson_schedule_versions version
                            ON version.lesson_id=last_lesson.id AND version.applicable_slot=1
                          WHERE last_lesson.term_id=term.id AND last_lesson.archived_at IS NULL
                       )) term_end_utc
                  FROM {$prefix}terms term
                  JOIN {$prefix}enrolments enrolment ON enrolment.id=term.enrolment_id
                  JOIN {$prefix}students student ON student.id=enrolment.student_id
                  JOIN {$prefix}courses course ON course.id=enrolment.course_id
                  LEFT JOIN {$prefix}teacher_assignments assignment
                    ON assignment.enrolment_id=enrolment.id AND assignment.applicable_slot=1
                  LEFT JOIN {$prefix}teachers teacher ON teacher.id=assignment.teacher_id
                 WHERE term.record_model='canonical_enrolment_term_v1' AND term.archived_at IS NULL
                   AND term.lifecycle_state='current' AND term.applicable_slot=1
                   AND enrolment.archived_at IS NULL AND enrolment.lifecycle_state IN ('current','authorised')
                   AND student.status='active' AND student.archived_at IS NULL
                   AND NOT EXISTS (
                       SELECT 1 FROM {$prefix}terms successor
                        WHERE successor.enrolment_id=term.enrolment_id
                          AND successor.sequence_number>term.sequence_number
                          AND successor.archived_at IS NULL
                   )
                   AND NOT EXISTS (
                       SELECT 1 FROM {$prefix}renewal_cycles cycle
                        JOIN {$prefix}recurring_enrolments recurring ON recurring.id=cycle.recurring_enrolment_id
                        WHERE recurring.enrolment_id=enrolment.id
                          AND cycle.state IN ('collected','term_bound','closed')
                   )
            ) candidate
             WHERE completed >= lesson_allocation - 4 AND completed < lesson_allocation
             ORDER BY term_id ASC
             LIMIT %d",
            self::BATCH
        ));
        if (!is_array($rows)) return $result;
        foreach ($rows as $row) {
            $result['examined']++;
            try {
                if (self::alreadyRecorded((int) $row->term_id)) {
                    $result['skipped']++;
                    continue;
                }
                $variables = self::variables($row);
                $recorded = PortalNotificationRecorder::student(
                    (int) $row->student_id,
                    self::EVENT_KEY,
                    self::SOURCE_KIND,
                    (int) $row->term_id,
                    $variables,
                    (string) $variables['payment_url']
                );
                if (!$recorded) {
                    $result['skipped']++;
                    continue;
                }
                self::sendEmail($row, $variables);
                $result['sent']++;
            } catch (\Throwable $error) {
                $result['errors']++;
                do_action('dzn_renewal_reminder_failed', (int) $row->term_id, get_class($error), (string) $error->getMessage());
            }
        }
        return $result;
    }

    /** One reminder per Term; the portal inbox is the durable idempotency record. */
    private static function alreadyRecorded(int $termId): bool {
        global $wpdb;
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}dzn_portal_notifications
              WHERE event_key=%s AND audience='student' AND source_kind=%s AND source_id=%d LIMIT 1",
            self::EVENT_KEY,
            self::SOURCE_KIND,
            $termId
        ));
        return $value !== null && $value !== false;
    }

    private static function variables(object $row): array {
        $locale = NotificationCatalogue::normaliseLocale((string) ($row->locale ?? ''));
        $studentName = trim((string) ($row->display_name ?? ''));
        $course = trim((string) ($row->course_name_fa ?? ''));
        if ($course === '') $course = trim((string) ($row->course_name_en ?? ''));
        $teacher = trim((string) ($row->teacher_display_name ?? ''));
        if ($teacher === '') $teacher = trim((string) ($row->teacher_english_name ?? $row->teacher_persian_name ?? ''));
        $remaining = max(0, (int) $row->lesson_allocation - (int) $row->completed);
        $termEnd = self::formatDate((string) ($row->term_end_utc ?? ''), (string) ($row->timezone ?? ''), (string) ($row->calendar_preference ?? ''), $locale);
        $portalUrl = home_url('/student-portal/');
        $paymentUrl = add_query_arg('portal-view', 'payments', $portalUrl);
        return array(
            'name' => $studentName,
            'course' => $course,
            'teacher' => $teacher,
            'remaining_sessions' => $locale === 'fa-IR' ? self::digits($remaining) : (string) $remaining,
            'term_end_date' => $termEnd !== '' ? $termEnd : ($locale === 'fa-IR' ? 'پایان ترم' : 'end of term'),
            'instalment_note' => self::instalmentNote($row, $locale),
            'payment_url' => $paymentUrl,
            'portal_url' => $portalUrl,
        );
    }

    /**
     * The renewal copy must not assume the current Term was paid in full.
     *
     * A two-instalment Term names how many instalments were settled and requires the remaining one
     * to be cleared before renewal; the next Term can still be paid in full or in two instalments.
     */
    private static function instalmentNote(object $row, string $locale): string {
        $planKind = (string) ($row->plan_kind ?? '');
        $total = max(0, (int) ($row->total_instalments ?? 0));
        $paid = max(0, (int) ($row->paid_instalments ?? 0));
        if ($planKind === 'two_instalments') {
            if ($total > 0 && $paid >= $total) {
                return $locale === 'fa-IR'
                    ? 'هر دو قسط ترم جاری پرداخت شده است. ترم بعد را می‌توانید یکجا یا در دو قسط پرداخت کنید.'
                    : 'Both instalments of the current term are paid. You can pay for the next term in full or in two instalments.';
            }
            return $locale === 'fa-IR'
                ? sprintf(
                    'ترم جاری شما دو قسطی است و %s از %s قسط پرداخت شده است. برای تمدید، ابتدا قسط باقی‌مانده را از بخش پرداخت تکمیل کنید و سپس ترم بعد را یکجا یا در دو قسط پرداخت کنید.',
                    self::digits($paid),
                    self::digits(max(1, $total))
                )
                : sprintf(
                    'The current term is paid in two instalments: %d of %d instalments are paid. Complete the remaining instalment before renewing; the next term can be paid in full or in two instalments.',
                    $paid,
                    max(1, $total)
                );
        }
        if ($planKind === 'full' && $total > 0 && $paid >= $total) {
            return $locale === 'fa-IR'
                ? 'پرداخت کامل ترم جاری انجام شده است. ترم بعد را می‌توانید یکجا یا در دو قسط پرداخت کنید.'
                : 'The current term is paid in full. You can pay for the next term in full or in two instalments.';
        }
        return $locale === 'fa-IR'
            ? 'ترم بعد را می‌توانید یکجا یا در دو قسط پرداخت کنید.'
            : 'You can pay for the next term in full or in two instalments.';
    }

    private static function sendEmail(object $row, array $variables): void {
        $email = trim((string) ($row->email ?? ''));
        if ($email === '' || (function_exists('is_email') && !is_email($email))) return;
        $fallbackSubject = 'دلنوازان · یادآوری تمدید ترم';
        $fallbackBody = "درود {{name}}،\n\nفقط {{remaining_sessions}} جلسه از ترم «{{course}}» با {{teacher}} باقی مانده است.\n\nبرای حفظ جایگاه کلاس، تمدید ترم را از بخش پرداخت حساب کاربری تکمیل کنید.\n\n{{instalment_note}}\n\nمهلت: {{term_end_date}}\n\nپرداخت و تمدید:\n{{payment_url}}\n\nحساب کاربری:\n{{portal_url}}\n\nاگر تا پایان ترم پرداخت را تکمیل نکنید یا با مدرس یا آکادمی هماهنگ نکنید، این جایگاه آزاد می‌شود و در دسترس هنرجویان دیگر قرار می‌گیرد.\n\nبا احترام، دلنوازان";
        $copy = array('subject' => $fallbackSubject, 'body' => $fallbackBody);
        if (class_exists(NotificationCopyResolver::class)) {
            $copy = NotificationCopyResolver::email(self::EVENT_KEY, NotificationCatalogue::PRIMARY_LOCALE, $variables, $copy);
        }
        $arguments = array(
            'locale' => NotificationCatalogue::normaliseLocale((string) ($row->locale ?? '')),
            'cta' => array('url' => (string) $variables['payment_url'], 'label' => 'پرداخت و تمدید'),
        );
        try {
            if (class_exists(DelnavazanEmailRenderer::class)) {
                DelnavazanEmailRenderer::send($email, (string) $copy['subject'], (string) $copy['body'], $arguments);
            } else {
                wp_mail($email, (string) $copy['subject'], (string) $copy['body']);
            }
        } catch (\Throwable $error) {
            do_action('dzn_renewal_reminder_email_failed', (int) ($row->term_id ?? 0), get_class($error));
        }
    }

    private static function formatDate(string $utc, string $timezone, string $calendar, string $locale): string {
        $timestamp = strtotime(trim($utc) . ' UTC');
        if (!$timestamp) return '';
        try { $zone = new \DateTimeZone($timezone !== '' ? $timezone : 'UTC'); } catch (\Throwable) { $zone = new \DateTimeZone('UTC'); }
        $calendar = in_array($calendar, array('persian', 'gregorian'), true) ? $calendar : ($locale === 'fa-IR' ? 'persian' : 'gregorian');
        if ($calendar === 'persian' && class_exists('IntlCalendar')) {
            try {
                $persian = \IntlCalendar::createInstance($zone, '@calendar=persian');
                $persian->setTime($timestamp * 1000);
                $months = array(1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور', 7 => 'مهر', 8 => 'آبان', 9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند');
                $month = (int) $persian->get(\IntlCalendar::FIELD_MONTH) + 1;
                return self::digits((int) $persian->get(\IntlCalendar::FIELD_DAY_OF_MONTH)) . ' ' . ($months[$month] ?? '') . ' ' . self::digits((int) $persian->get(\IntlCalendar::FIELD_YEAR));
            } catch (\Throwable) {
                // Fall through to the Gregorian presentation below.
            }
        }
        if (function_exists('wp_date')) return (string) wp_date('j F Y', $timestamp, $zone);
        return gmdate('j F Y', $timestamp);
    }

    private static function digits(int $value): string {
        return strtr((string) $value, array('0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹'));
    }

    private static function schedulingEnabled(): bool {
        if (!function_exists('wp_get_environment_type') || !function_exists('apply_filters')) return false;
        $default = in_array(wp_get_environment_type(), array('staging', 'production'), true);
        return (bool) apply_filters('dzn_renewal_reminder_scheduling_enabled', $default);
    }
}
