<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * Human-labelled operator choices for issuing canonical Term Lessons.
 *
 * This is a read projection only. CanonicalLessonAuthorityService remains the only writer and
 * re-proves the Term, Enrolment, Teacher Assignment, allocation and replacement eligibility.
 */
final class CanonicalLessonIssueReadService {
    public function options(): array {
        if (!current_user_can('dzn_manage_canonical_lessons')) throw new \RuntimeException('Unauthorized');
        return array(
            'terms' => $this->terms(),
            'replacements' => $this->replacementOrigins(),
        );
    }

    /** Current canonical Terms that have a current Teacher Assignment. */
    private function terms(): array {
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $rows = $wpdb->get_results(
            "SELECT t.id term_id,t.reference_code term_reference,t.lesson_allocation,t.replacement_allowance,
                    e.id enrolment_id,e.reference_code enrolment_reference,
                    a.id assignment_id,
                    COALESCE(NULLIF(s.display_name,''),NULLIF(s.first_name,''),s.last_name) student_name,
                    COALESCE(NULLIF(c.name_en,''),c.name_fa) course_name,
                    COALESCE(NULLIF(tr.display_name,''),NULLIF(tr.english_name,''),tr.persian_name) teacher_name,
                    (SELECT COUNT(*) FROM {$p}lessons l WHERE l.term_id=t.id AND l.record_model='canonical_term_lesson_v1' AND l.lesson_type='standard') standard_count,
                    (SELECT COUNT(*) FROM {$p}lessons l WHERE l.term_id=t.id AND l.record_model='canonical_term_lesson_v1' AND l.lesson_type='replacement') replacement_count
             FROM {$p}terms t
             INNER JOIN {$p}enrolments e ON e.id=t.enrolment_id
             INNER JOIN {$p}teacher_assignments a ON a.enrolment_id=e.id AND a.applicable_slot=1 AND a.state='assigned'
             LEFT JOIN {$p}students s ON s.id=e.student_id
             LEFT JOIN {$p}courses c ON c.id=e.course_id
             LEFT JOIN {$p}teachers tr ON tr.id=a.teacher_id
             WHERE t.record_model='canonical_enrolment_term_v1'
               AND t.lifecycle_state='current'
               AND t.applicable_slot=1
               AND t.archived_at IS NULL
               AND e.record_model='canonical_student_course_v1'
               AND e.lifecycle_state='current'
               AND e.applicable_slot=1
               AND e.archived_at IS NULL
             ORDER BY t.id DESC LIMIT 100"
        ) ?: array();
        if ($wpdb->last_error !== '') throw new \RuntimeException('Canonical Lesson choices are unavailable');

        $options = array();
        foreach ($rows as $row) {
            $allocated = (int) $row->lesson_allocation;
            $issued = (int) $row->standard_count;
            $remaining = max(0, $allocated - $issued);
            $student = (string) ($row->student_name ?: 'Student');
            $course = (string) ($row->course_name ?: 'Course');
            $teacher = (string) ($row->teacher_name ?: 'Teacher');
            $term = (string) ($row->term_reference ?: ('Term #' . (int) $row->term_id));
            $options[] = array(
                'term_id' => (int) $row->term_id,
                'assignment_id' => (int) $row->assignment_id,
                'enrolment_id' => (int) $row->enrolment_id,
                'standard_remaining' => $remaining,
                'replacement_remaining' => max(0, (int) $row->replacement_allowance - (int) $row->replacement_count),
                'label' => $student . ' · ' . $course . ' · ' . $teacher . ' · ' . $term . ' · ' . $issued . '/' . $allocated . ' standard Lessons',
            );
        }
        return $options;
    }

    /** Cancelled standard Lessons that are eligible for exactly one replacement Lesson. */
    private function replacementOrigins(): array {
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $rows = $wpdb->get_results(
            "SELECT l.id lesson_id,l.reference_code lesson_reference,l.term_id,l.teacher_assignment_id,
                    t.reference_code term_reference,
                    COALESCE(NULLIF(s.display_name,''),NULLIF(s.first_name,''),s.last_name) student_name,
                    COALESCE(NULLIF(c.name_en,''),c.name_fa) course_name
             FROM {$p}lessons l
             INNER JOIN {$p}terms t ON t.id=l.term_id
             INNER JOIN {$p}enrolments e ON e.id=l.enrolment_id
             INNER JOIN {$p}canonical_lesson_lifecycle_events ev ON ev.lesson_id=l.id AND ev.to_state='cancelled' AND ev.reason_code='canonical_lesson_cancelled_replacement_eligible'
             LEFT JOIN {$p}students s ON s.id=l.student_id
             LEFT JOIN {$p}courses c ON c.id=l.course_id
             WHERE l.record_model='canonical_term_lesson_v1'
               AND l.lesson_type='standard'
               AND l.lifecycle_state='cancelled'
               AND l.archived_at IS NULL
               AND t.record_model='canonical_enrolment_term_v1'
               AND t.lifecycle_state='current'
               AND e.record_model='canonical_student_course_v1'
               AND NOT EXISTS (SELECT 1 FROM {$p}lessons r WHERE r.canonical_replacement_origin_lesson_id=l.id)
             ORDER BY l.id DESC LIMIT 100"
        ) ?: array();
        if ($wpdb->last_error !== '') throw new \RuntimeException('Replacement Lesson choices are unavailable');

        $options = array();
        foreach ($rows as $row) {
            $student = (string) ($row->student_name ?: 'Student');
            $course = (string) ($row->course_name ?: 'Course');
            $term = (string) ($row->term_reference ?: ('Term #' . (int) $row->term_id));
            $lesson = (string) ($row->lesson_reference ?: ('Lesson #' . (int) $row->lesson_id));
            $options[] = array(
                'lesson_id' => (int) $row->lesson_id,
                'term_id' => (int) $row->term_id,
                'assignment_id' => (int) $row->teacher_assignment_id,
                'label' => $student . ' · ' . $course . ' · ' . $term . ' · ' . $lesson,
            );
        }
        return $options;
    }
}
