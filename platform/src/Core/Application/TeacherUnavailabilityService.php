<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\PrincipalInvitationRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\TeacherAvailabilityRepository;

/**
 * Effective-dated temporary unavailability.
 *
 * The canonical availability authority already distinguishes recurring weekly
 * windows from dated exceptions. A temporary unavailable period is therefore
 * recorded as dated all-day `blocked` exceptions in the Teacher's own timezone,
 * not by rewriting weekly availability or historical attendance. New booking
 * eligibility immediately respects those exceptions through
 * TeacherAvailabilityService::effective(); existing confirmed lessons are never
 * cancelled by this service.
 */
final class TeacherUnavailabilityService {
    private const CAPABILITY = 'dzn_manage_teacher_availability';
    private const REASON = 'temporary_unavailability';
    private const MAX_DAYS = 366;

    public function __construct(
        private ?TeacherAvailabilityRepository $availability = null,
        private ?PrincipalInvitationRepository $audit = null
    ) {
        $this->availability ??= new TeacherAvailabilityRepository();
        $this->audit ??= new PrincipalInvitationRepository();
    }

    /** @return array{teacher:object,profile:object,timezone:string,from:string,to:string,start_utc:string,end_utc:string,conflicts:list<array<string,mixed>>} */
    public function preview(int $teacherId, string $from, string $to = ''): array {
        $this->authorize();
        $teacher = $this->teacher($teacherId);
        $profile = $this->availability->profile($teacherId);
        if (!$profile || (string) $profile->status !== 'active') throw new \InvalidArgumentException('An active availability profile is required before a temporary period can be recorded');
        $timezone = $this->timezone((string) $profile->timezone);
        $zone = new \DateTimeZone($timezone);
        $today = new \DateTimeImmutable('today', $zone);
        $start = $this->localDate($from, $zone);
        $end = $to === '' ? $start->modify('+' . (90 - 1) . ' days') : $this->localDate($to, $zone);
        if ($end < $start) throw new \InvalidArgumentException('The unavailable period must end on or after its start date');
        if ($start < $today) throw new \InvalidArgumentException('A temporary unavailable period cannot begin in the past');
        if ($start->diff($end)->days + 1 > self::MAX_DAYS) throw new \InvalidArgumentException('A temporary unavailable period cannot exceed ' . self::MAX_DAYS . ' days');
        $startUtc = $start->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $endUtc = $end->modify('+1 day')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        return array(
            'teacher' => $teacher,
            'profile' => $profile,
            'timezone' => $timezone,
            'from' => $start->format('Y-m-d'),
            'to' => $end->format('Y-m-d'),
            'start_utc' => $startUtc,
            'end_utc' => $endUtc,
            'conflicts' => $this->conflicts($teacherId, $startUtc, $endUtc),
        );
    }

    /** Record the period after any conflict has been explicitly acknowledged. */
    public function apply(int $teacherId, string $from, string $to, string $reason, bool $acknowledgeConflicts = false): array {
        $preview = $this->preview($teacherId, $from, $to);
        $reason = sanitize_key($reason);
        if ($reason === '') throw new \InvalidArgumentException('A reason is required for auditability');
        if ($preview['conflicts'] && !$acknowledgeConflicts) throw new \InvalidArgumentException('unavailability_conflicts');
        $profile = $preview['profile'];
        $timezone = $preview['timezone'];
        $zone = new \DateTimeZone($timezone);
        $actor = get_current_user_id() ?: null;
        $now = current_time('mysql', true);
        $days = 0;
        $this->availability->begin();
        try {
            $start = new \DateTimeImmutable($preview['from'], $zone);
            $end = new \DateTimeImmutable($preview['to'], $zone);
            for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
                $this->availability->saveException(array(
                    'teacher_id' => $teacherId,
                    'profile_id' => (int) $profile->id,
                    'local_date' => $date->format('Y-m-d'),
                    'all_day' => 1,
                    'local_start_time' => '00:00:00',
                    'local_end_time' => '00:00:00',
                    'state' => 'blocked',
                    'timezone' => $timezone,
                    'status' => 'active',
                    'reason_code' => self::REASON,
                ), $now, $actor);
                $days++;
            }
            $this->audit->audit('teacher', $teacherId, 'teacher.unavailability_scheduled', $actor, hash('sha256', 'teacher-unavailability:' . $teacherId . ':' . $preview['from'] . ':' . $preview['to'] . ':' . wp_generate_uuid4()), substr('reason=' . $reason . ';days=' . $days, 0, 191));
            $this->availability->commit();
        } catch (\Throwable $error) {
            $this->availability->rollback();
            throw $error;
        }
        return array('days' => $days, 'from' => $preview['from'], 'to' => $preview['to'], 'conflicts' => $preview['conflicts']);
    }

    /** Reactivation removes only future temporary-unavailability exceptions; it never rewrites history. */
    public function clearFuture(int $teacherId): int {
        $this->authorize();
        global $wpdb;
        $table = $wpdb->prefix . 'dzn_teacher_availability_exceptions';
        $profile = $this->availability->profile($teacherId);
        if (!$profile) return 0;
        $timezone = $this->timezone((string) $profile->timezone);
        $today = (new \DateTimeImmutable('today', new \DateTimeZone($timezone)))->format('Y-m-d');
        $actor = get_current_user_id() ?: null;
        $now = current_time('mysql', true);
        $this->availability->begin();
        try {
            $count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE teacher_id=%d AND status='active' AND state='blocked' AND reason_code=%s AND local_date>=%s",
                $teacherId, self::REASON, $today
            ));
            $changed = $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET status='inactive',reason_code='teacher_reactivated',version=version+1,updated_at=%s,updated_by=%d WHERE teacher_id=%d AND status='active' AND state='blocked' AND reason_code=%s AND local_date>=%s",
                $now, (int) $actor, $teacherId, self::REASON, $today
            ));
            if ($changed === false) throw new \RuntimeException('Temporary availability could not be cleared.');
            $this->audit->audit('teacher', $teacherId, 'teacher.unavailability_cleared', $actor, hash('sha256', 'teacher-unavailability-clear:' . $teacherId . ':' . $now . ':' . wp_generate_uuid4()), 'reason=teacher_reactivated;exceptions=' . $count);
            $this->availability->commit();
        } catch (\Throwable $error) {
            $this->availability->rollback();
            throw $error;
        }
        return max(0, $count);
    }

    /** @return list<array<string,mixed>> */
    private function conflicts(int $teacherId, string $startUtc, string $endUtc): array {
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT 'lesson' kind,l.id identity,l.reference_code,v.starts_at_utc,v.schedule_timezone,
                    COALESCE(NULLIF(s.display_name,''),'Student') student_name,
                    COALESCE(NULLIF(c.name_en,''),c.name_fa,'Class') course_name
             FROM {$p}lessons l
             INNER JOIN {$p}canonical_lesson_schedule_versions v ON v.lesson_id=l.id AND v.applicable_slot=1
             LEFT JOIN {$p}students s ON s.id=l.student_id
             LEFT JOIN {$p}courses c ON c.id=l.course_id
             WHERE l.teacher_id=%d AND l.archived_at IS NULL AND l.lifecycle_state IN ('authorised','completed')
               AND v.starts_at_utc>=%s AND v.starts_at_utc<%s
             ORDER BY v.starts_at_utc LIMIT 50",
            $teacherId, $startUtc, $endUtc
        )) ?: array();
        $intros = $wpdb->get_results($wpdb->prepare(
            "SELECT 'intro' kind,bc.id identity,r.reference_code,bc.starts_at_utc,bc.schedule_timezone,
                    COALESCE(NULLIF(cs.full_name,''),'Prospective student') student_name,
                    COALESCE(NULLIF(co.name_en,''),co.name_fa,'Introductory lesson') course_name
             FROM {$p}booking_request_confirmations bc
             INNER JOIN {$p}booking_requests r ON r.id=bc.booking_request_id
             LEFT JOIN {$p}booking_request_contact_snapshots cs ON cs.booking_request_id=bc.booking_request_id AND cs.snapshot_sequence=(SELECT MAX(cs2.snapshot_sequence) FROM {$p}booking_request_contact_snapshots cs2 WHERE cs2.booking_request_id=bc.booking_request_id)
             LEFT JOIN {$p}courses co ON co.id=r.selected_intro_course_id
             WHERE bc.teacher_id=%d AND bc.state='confirmed'
               AND bc.starts_at_utc>=%s AND bc.starts_at_utc<%s
             ORDER BY bc.starts_at_utc LIMIT 50",
            $teacherId, $startUtc, $endUtc
        )) ?: array();
        $out = array();
        foreach (array_merge($rows, $intros) as $row) {
            $out[] = array(
                'kind' => (string) $row->kind,
                'identity' => (int) $row->identity,
                'reference' => (string) ($row->reference_code ?? ''),
                'starts_at_utc' => (string) $row->starts_at_utc,
                'timezone' => (string) $row->schedule_timezone,
                'student' => (string) $row->student_name,
                'course' => (string) $row->course_name,
            );
        }
        usort($out, static fn(array $a, array $b): int => strcmp($a['starts_at_utc'], $b['starts_at_utc']));
        return $out;
    }

    private function teacher(int $teacherId): object {
        if ($teacherId < 1) throw new \InvalidArgumentException('Teacher identity required');
        $row = $this->availability->teacherForUpdate($teacherId);
        if (!$row || (string) $row->status !== 'active' || $row->archived_at !== null) throw new \InvalidArgumentException('Active Teacher required');
        return $row;
    }

    private function localDate(string $value, \DateTimeZone $zone): \DateTimeImmutable {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) throw new \InvalidArgumentException('A valid local date is required');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);
        if (!$date || $date->format('Y-m-d') !== $value) throw new \InvalidArgumentException('A valid local date is required');
        return $date;
    }

    private function timezone(string $value): string {
        if ($value === '' || !in_array($value, timezone_identifiers_list(), true)) throw new \InvalidArgumentException('The availability timezone is invalid');
        return $value;
    }

    private function authorize(): void {
        if (!current_user_can(self::CAPABILITY)) throw new \RuntimeException('Unauthorized');
    }
}
