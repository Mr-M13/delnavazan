<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\{CanonicalLessonAuthorityRepository, LessonRepository, OperationalExceptionRepository};

/** Read-only operator queue for Classes. Canonical facts stay validated by their owning read models. */
final class LessonOperationsReadService {
    public function recent(): array {
        $this->requireCapability();
        $rows = $this->baseRows(null);
        foreach ($rows as $row) $this->project($row);
        usort($rows, static function(object $a, object $b): int {
            $rank = static fn(object $row): int => in_array($row->class_status, array('Integrity review needed', 'Schedule needs review'), true) ? 0 : ($row->class_status === 'Ready to schedule' ? 1 : ($row->class_status === 'Scheduled' ? 2 : 3));
            $order = $rank($a) <=> $rank($b);
            if ($order !== 0) return $order;
            if ($rank($a) === 2) {
                $order = strcmp((string) $a->schedule_sort, (string) $b->schedule_sort);
                if ($order !== 0) return $order;
            }
            return (int) $b->id <=> (int) $a->id;
        });
        return $rows;
    }

    /** @return array<int,object> */
    public function forEnrolment(int $enrolmentId): array {
        $this->requireCapability();
        if ($enrolmentId < 1) return array();
        $rows = $this->baseRows($enrolmentId);
        foreach ($rows as $row) $this->project($row);
        usort($rows, static fn(object $a, object $b): int => strcmp((string) $a->schedule_sort, (string) $b->schedule_sort) ?: ((int) $a->id <=> (int) $b->id));
        return $rows;
    }

    public function canonicalLesson(int $lessonId): ?object {
        $lesson = $this->validatedCanonicalLesson($lessonId);
        if ($lesson) $this->project($lesson);
        return $lesson;
    }

    private function validatedCanonicalLesson(int $lessonId): ?object {
        if (!current_user_can('dzn_manage_canonical_lessons')) throw new \RuntimeException('Unauthorized');
        if ($lessonId < 1) throw new \InvalidArgumentException('Lesson identity required');
        $repository = new CanonicalLessonAuthorityRepository();
        $lesson = $repository->lesson($lessonId);
        if (!$lesson || ($lesson->record_model ?? null) !== 'canonical_term_lesson_v1') return null;
        if (!CanonicalLessonAuthorityValidator::valid($lesson, $repository->events($lessonId), $repository)) throw new \InvalidArgumentException('data_integrity_conflict');
        $labels = $this->labels($lessonId);
        $lesson->student_name = (string) $labels->student_name;
        $lesson->teacher_name = (string) $labels->teacher_name;
        $lesson->course_name = (string) $labels->course_name;
        $lesson->instrument_name = (string) $labels->instrument_name;
        $lesson->enrolment_reference = (string) $labels->enrolment_reference;
        return $lesson;
    }

    public function operatorRecord(int $lessonId): ?object {
        $this->requireCapability();
        $lesson = (new LessonRepository())->find($lessonId);
        if (!$lesson) return current_user_can('dzn_manage_canonical_lessons') ? $this->canonicalLesson($lessonId) : null;
        $labels = $this->labels($lessonId);
        $lesson->student_name = (string) $labels->student_name;
        $lesson->teacher_name = (string) $labels->teacher_name;
        $lesson->course_name = (string) $labels->course_name;
        $lesson->instrument_name = (string) $labels->instrument_name;
        $lesson->enrolment_reference = (string) $labels->enrolment_reference;
        $this->project($lesson);
        return $lesson;
    }

    /** @return array<int,object> */
    private function baseRows(?int $enrolmentId): array {
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $models = current_user_can('dzn_manage_canonical_lessons') ? "('legacy_phase1','canonical_term_lesson_v1')" : "('legacy_phase1')";
        $where = "l.record_model IN {$models} AND l.archived_at IS NULL";
        $sql = "SELECT l.id,l.reference_code,l.record_model,l.lesson_type,l.status,l.lifecycle_state,l.canonical_sequence,l.current_schedule_version_id,l.enrolment_id,COALESCE(NULLIF(s.display_name,''),NULLIF(s.first_name,''),s.last_name) student_name,COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name) teacher_name,COALESCE(NULLIF(c.name_en,''),c.name_fa) course_name,COALESCE(NULLIF(i.name_en,''),i.name_fa) instrument_name,e.reference_code enrolment_reference,legacy.local_wall_date,legacy.local_wall_time,legacy.schedule_timezone FROM {$p}lessons l LEFT JOIN {$p}students s ON s.id=l.student_id LEFT JOIN {$p}teachers t ON t.id=l.teacher_id LEFT JOIN {$p}courses c ON c.id=l.course_id LEFT JOIN {$p}instruments i ON i.id=c.instrument_id LEFT JOIN {$p}enrolments e ON e.id=l.enrolment_id LEFT JOIN {$p}lesson_schedule_versions legacy ON legacy.id=l.current_schedule_version_id AND legacy.lesson_id=l.id AND legacy.superseded_at IS NULL WHERE {$where}";
        if ($enrolmentId !== null) $sql = $wpdb->prepare($sql . ' AND l.enrolment_id=%d ORDER BY l.id DESC', $enrolmentId);
        else $sql .= ' ORDER BY l.id DESC LIMIT 100';
        $rows = $wpdb->get_results($sql);
        if ($wpdb->last_error !== '') throw new \RuntimeException('Class operator read failed: ' . $wpdb->last_error);
        return is_array($rows) ? $rows : array();
    }

    private function labels(int $lessonId): object {
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $labels = $wpdb->get_row($wpdb->prepare("SELECT COALESCE(NULLIF(s.display_name,''),NULLIF(s.first_name,''),s.last_name) student_name,COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name) teacher_name,COALESCE(NULLIF(c.name_en,''),c.name_fa) course_name,COALESCE(NULLIF(i.name_en,''),i.name_fa) instrument_name,e.reference_code enrolment_reference FROM {$p}lessons l LEFT JOIN {$p}students s ON s.id=l.student_id LEFT JOIN {$p}teachers t ON t.id=l.teacher_id LEFT JOIN {$p}courses c ON c.id=l.course_id LEFT JOIN {$p}instruments i ON i.id=c.instrument_id LEFT JOIN {$p}enrolments e ON e.id=l.enrolment_id WHERE l.id=%d", $lessonId));
        if (!$labels || $wpdb->last_error !== '') throw new \RuntimeException('Class identity read failed: ' . $wpdb->last_error);
        return $labels;
    }

    private function project(object $row): void {
        $row->class_status = '';
        $row->schedule_label = 'Not scheduled';
        $row->schedule_sort = '9999-12-31 23:59:59';
        $row->delivery_label = 'Not recorded';
        $row->attendance_label = 'Not recorded';
        $row->exception_label = current_user_can('dzn_manage_exceptions') ? 'No active exceptions' : 'Exception status unavailable';
        $attention = array();
        if (($row->record_model ?? 'legacy_phase1') === 'legacy_phase1') {
            $row->class_status = match ((string) $row->status) {'draft' => 'Ready to schedule', 'scheduled' => 'Scheduled', 'completed' => 'Completed', 'cancelled' => 'Cancelled', default => 'Review class status'};
            if ((int) $row->current_schedule_version_id > 0 && $row->local_wall_date && $row->local_wall_time) {
                $row->schedule_label = (string) $row->local_wall_date . ' · ' . substr((string) $row->local_wall_time, 0, 5) . ' ' . (string) $row->schedule_timezone;
                $row->schedule_sort = (string) $row->local_wall_date . ' ' . (string) $row->local_wall_time;
            } elseif ((int) $row->current_schedule_version_id > 0 || $row->status === 'scheduled') {
                $row->class_status = 'Schedule needs review';
                $row->schedule_label = 'Schedule unavailable';
                $attention[] = 'Schedule needs review';
            }
        } else $this->canonicalProjection($row, $attention);
        if ($row->class_status === 'Ready to schedule') $attention[] = 'No schedule recorded';
        if (current_user_can('dzn_manage_exceptions')) {
            $count = (new OperationalExceptionRepository())->activeCountFor('lesson', (int) $row->id);
            if ($count > 0) {
                $row->exception_label = $count . ' active exception' . ($count === 1 ? '' : 's');
                $attention[] = $row->exception_label;
            }
        }
        $row->attention = $attention ? implode(' · ', array_values(array_unique($attention))) : 'No attention recorded';
        $row->next_action = $row->class_status === 'Ready to schedule' ? 'Open class' : 'Review class';
    }

    private function canonicalProjection(object $row, array &$attention): void {
        try {
            $canonical = $this->validatedCanonicalLesson((int) $row->id);
            if (!$canonical) throw new \InvalidArgumentException('data_integrity_conflict');
            $row->class_status = match ((string) $canonical->lifecycle_state) {'authorised' => 'Ready to schedule', 'completed' => 'Completed', 'cancelled' => 'Cancelled', default => 'Review class status'};
            if (!current_user_can('dzn_manage_canonical_lesson_schedules')) {
                $row->schedule_label = 'Schedule status unavailable (scheduling access required)';
                $row->delivery_label = 'Delivery status unavailable';
                $row->attendance_label = 'Attendance status unavailable';
                return;
            }
            $schedule = (new CanonicalLessonScheduleReadService())->forLesson((int) $row->id);
            if ($schedule['active_version']) {
                $active = $schedule['active_version'];
                $row->schedule_label = (string) $active->local_wall_date . ' · ' . substr((string) $active->local_wall_time, 0, 5) . ' ' . (string) $active->schedule_timezone;
                $row->schedule_sort = (string) $active->starts_at_utc;
                if ($canonical->lifecycle_state === 'authorised') $row->class_status = 'Scheduled';
                $this->deliveryAndAttendance($row, (int) $active->id, $attention);
            }
        } catch (\InvalidArgumentException) {
            $row->class_status = 'Integrity review needed';
            $row->schedule_label = 'Schedule unavailable';
            $row->delivery_label = 'Delivery status unavailable';
            $row->attendance_label = 'Attendance status unavailable';
            $attention[] = 'Class lifecycle or schedule needs integrity review';
        }
    }

    private function deliveryAndAttendance(object $row, int $scheduleVersionId, array &$attention): void {
        if (current_user_can('dzn_manage_canonical_lesson_delivery')) {
            try {
                $delivery = (new CanonicalLessonDeliveryReadService())->forLesson((int) $row->id);
                $effective = $delivery['effective'];
                if ($effective) {
                    $row->delivery_label = self::label((string) $effective->outcome_code);
                    $row->attendance_label = self::label((string) $effective->attendance_state);
                    if ((string) $effective->remedy_class !== 'none') $attention[] = 'Remedy: ' . self::label((string) $effective->remedy_class);
                }
            } catch (\InvalidArgumentException) {
                $row->delivery_label = 'Delivery truth needs integrity review';
                $attention[] = $row->delivery_label;
            }
        } else $row->delivery_label = 'Delivery status unavailable';
        if (!current_user_can('dzn_view_canonical_attendance_review')) return;
        try {
            $attendance = (new CanonicalAttendanceReadService())->forOccurrence((int) $row->id, $scheduleVersionId);
            $state = (string) $attendance['case']['state'];
            $row->attendance_label = self::label($state);
            if (in_array($state, array('open', 'ready_for_review', 'settlement_pending'), true)) $attention[] = 'Attendance ' . self::label($state);
        } catch (\InvalidArgumentException $exception) {
            if ($exception->getMessage() !== 'canonical_attendance_case_required') {
                $row->attendance_label = 'Attendance needs integrity review';
                $attention[] = $row->attendance_label;
            }
        }
    }

    private function requireCapability(): void { if (!current_user_can('dzn_manage_lessons')) throw new \RuntimeException('Unauthorized'); }
    private static function label(string $value): string { return ucfirst(str_replace('_', ' ', $value)); }
}
