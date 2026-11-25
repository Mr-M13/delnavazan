<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Attendance confirmation escalation.
 *
 * Owner decision (4), 2026-11-25: when an ended occurrence has no automatic delivery settlement and
 * the Teacher has not confirmed inside the manual window, the Teacher is reminded after 24 hours and
 * the case is referred to the administrator after 48 hours.
 *
 * This worker never decides delivery. It only nudges and refers; the delivery authority and the
 * Teacher/manual path stay the only ways an outcome is recorded. Each stage is written once per
 * attendance case to `platform_audit_events`, so a repeated run (or a crashed lease) cannot send the
 * same reminder twice.
 */
final class AttendanceEscalationWorker {
    public const CRON_HOOK = 'dzn_attendance_escalation';
    public const TEACHER_AFTER_HOURS = 24;
    public const ADMIN_AFTER_HOURS = 48;
    public const LOOKBACK_DAYS = 7;

    public static function register(): void {
        add_action( self::CRON_HOOK, static function (): void {
            try {
                ( new self() )->run();
            } catch ( \Throwable $error ) {
                do_action( 'dzn_attendance_escalation_failed', $error->getMessage() );
            }
        } );
        add_action( 'init', static function (): void {
            if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
                wp_schedule_event( time() + 600, 'hourly', self::CRON_HOOK );
            }
        } );
    }

    /** @return array{teacher:int,admin:int,skipped:int} */
    public function run( int $limit = 50 ): array {
        global $wpdb;
        $p    = $wpdb->prefix . 'dzn_';
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT c.id case_id,c.lesson_id,c.schedule_version_id,c.state,
                    v.starts_at_utc,v.ends_at_utc,v.schedule_timezone,
                    COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name,'') teacher_name,
                    t.email teacher_email,
                    COALESCE(NULLIF(s.display_name,''),NULLIF(s.reference_code,''),'هنرجو') student_name,
                    COALESCE(NULLIF(i.name_fa,''),NULLIF(course.name_fa,''),NULLIF(course.name_en,''),'کلاس') course_name
               FROM {$p}canonical_attendance_cases c
               INNER JOIN {$p}canonical_lesson_schedule_versions v ON v.id=c.schedule_version_id
               INNER JOIN {$p}lessons l ON l.id=c.lesson_id AND l.archived_at IS NULL
               INNER JOIN {$p}teachers t ON t.id=l.teacher_id
               INNER JOIN {$p}students s ON s.id=l.student_id
               LEFT JOIN {$p}courses course ON course.id=l.course_id
               LEFT JOIN {$p}instruments i ON i.id=course.instrument_id
              WHERE c.state IN ('open','ready_for_review')
                AND v.ends_at_utc <= UTC_TIMESTAMP() - INTERVAL %d HOUR
                AND v.ends_at_utc >  UTC_TIMESTAMP() - INTERVAL %d DAY
              ORDER BY v.ends_at_utc ASC
              LIMIT %d",
            self::TEACHER_AFTER_HOURS,
            self::LOOKBACK_DAYS,
            max( 1, min( 200, $limit ) )
        ) );
        $out = array( 'teacher' => 0, 'admin' => 0, 'skipped' => 0 );
        if ( ! is_array( $rows ) ) {
            return $out;
        }
        $now = (int) strtotime( gmdate( 'Y-m-d H:i:s' ) . ' UTC' );
        foreach ( $rows as $row ) {
            $ended = (int) strtotime( (string) $row->ends_at_utc . ' UTC' );
            $stage = ( $now - $ended >= self::ADMIN_AFTER_HOURS * 3600 ) ? 'admin' : 'teacher';
            if ( $this->alreadySent( (int) $row->case_id, $stage ) ) {
                $out['skipped']++;
                continue;
            }
            $sent = 'teacher' === $stage ? $this->notifyTeacher( $row ) : $this->notifyAdmin( $row );
            if ( ! $sent ) {
                $out['skipped']++;
                continue;
            }
            $this->record( (int) $row->case_id, (int) $row->lesson_id, $stage );
            $out[ $stage ]++;
        }
        return $out;
    }

    private function alreadySent( int $caseId, string $stage ): bool {
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}platform_audit_events WHERE aggregate_type='canonical_attendance_case' AND aggregate_id=%d AND event_type=%s LIMIT 1",
            $caseId,
            'attendance.escalation.' . $stage
        ) );
    }

    private function record( int $caseId, int $lessonId, string $stage ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'dzn_platform_audit_events', array(
            'aggregate_type' => 'canonical_attendance_case',
            'aggregate_id'   => $caseId,
            'event_type'     => 'attendance.escalation.' . $stage,
            'actor_type'     => 'system',
            'actor_id'       => null,
            'reason_code'    => 'attendance_confirmation_overdue',
            'safe_detail'    => wp_json_encode( array( 'lesson_id' => $lessonId, 'stage' => $stage ) ),
            'idempotency_key' => 'attendance-escalation-' . $stage . '-' . $caseId,
            'occurred_at'    => gmdate( 'Y-m-d H:i:s' ),
        ) );
    }

    private function when( object $row ): string {
        $timezone = trim( (string) ( $row->schedule_timezone ?? 'UTC' ) );
        if ( ! in_array( $timezone, timezone_identifiers_list(), true ) ) {
            $timezone = 'UTC';
        }
        return wp_date( 'Y-m-d H:i', (int) strtotime( (string) $row->starts_at_utc . ' UTC' ), new \DateTimeZone( $timezone ) ) . ' (' . $timezone . ')';
    }

    private function notifyTeacher( object $row ): bool {
        $email = trim( (string) ( $row->teacher_email ?? '' ) );
        if ( $email === '' || ! is_email( $email ) ) {
            return false;
        }
        $name    = trim( (string) $row->teacher_name );
        $when    = $this->when( $row );
        $student = trim( (string) $row->student_name );
        $course  = trim( (string) $row->course_name );
        $portal  = home_url( '/teacher-portal/' );
        $subject = 'دلنوازان · تأیید برگزاری کلاس را ثبت کنید';
        $body    = 'درود' . ( $name !== '' ? ' ' . $name : '' ) . "،\n\n"
            . 'برای کلاس «' . $course . '» با هنرجو ' . $student . ' در ' . $when . ' هنوز تأیید برگزاری ثبت نشده است. '
            . 'اگر کلاس برگزار شد، لطفاً از پرتال مدرس «تأیید برگزاری و حضور» را ثبت کنید. '
            . 'اگر برگزار نشد، همانجا وضعیت درست را ثبت کنید.' . "\n\n"
            . 'اگر تا ۴۸ ساعت پس از پایان کلاس تأییدی ثبت نشود، پرونده برای بررسی به مدیر آکادمی ارجاع می‌شود.' . "\n\n"
            . $portal . "\n\nدلنوازان";
        return $this->send( $email, $subject, $body, 'teacher' );
    }

    private function notifyAdmin( object $row ): bool {
        $email = trim( (string) get_option( 'admin_email' ) );
        if ( $email === '' || ! is_email( $email ) ) {
            return false;
        }
        $when    = $this->when( $row );
        $student = trim( (string) $row->student_name );
        $teacher = trim( (string) $row->teacher_name );
        $course  = trim( (string) $row->course_name );
        $review  = admin_url( 'admin.php?page=dzn-attendance-review' );
        $subject = 'دلنوازان · کلاس بدون تأیید برگزاری (ارجاع به مدیر)';
        $body    = 'درود،' . "\n\n"
            . 'کلاس «' . $course . '» با هنرجو ' . $student . ' و مدرس ' . $teacher . ' در ' . $when . ' '
            . 'بیش از ۴۸ ساعت است که نه به‌صورت خودکار و نه با تأیید مدرس ثبت نشده است.' . "\n\n"
            . 'این پرونده به بررسی مدیر ارجاع شد: ' . $review . "\n\nدلنوازان";
        return $this->send( $email, $subject, $body, 'admin' );
    }

    private function send( string $email, string $subject, string $body, string $audience ): bool {
        try {
            if ( class_exists( \Delnavazan\Platform\Integrations\Notifications\DelnavazanEmailRenderer::class ) ) {
                return \Delnavazan\Platform\Integrations\Notifications\DelnavazanEmailRenderer::send(
                    $email,
                    $subject,
                    $body,
                    array( 'locale' => 'fa-IR', 'audience' => $audience )
                );
            }
            return (bool) wp_mail( $email, $subject, $body );
        } catch ( \Throwable ) {
            return false;
        }
    }
}
