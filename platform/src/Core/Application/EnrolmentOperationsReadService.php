<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\{EnrolmentRepository, OperationalExceptionRepository, TeacherAssignmentRepository};

/**
 * Read-only enrolment workspace projection. It deliberately composes existing
 * enrolment, assignment and class read authorities and has no lifecycle or
 * scheduling writer of its own.
 */
final class EnrolmentOperationsReadService {
    public function recent(): array {
        $this->requireCapability();
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $rows = $wpdb->get_results("SELECT e.*, COALESCE(NULLIF(s.display_name,''),NULLIF(s.first_name,''),s.last_name) student_name, COALESCE(NULLIF(c.name_en,''),c.name_fa) course_name, COALESCE(NULLIF(i.name_en,''),i.name_fa) instrument_name, COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name) enrolled_teacher_name FROM {$p}enrolments e LEFT JOIN {$p}students s ON s.id=e.student_id LEFT JOIN {$p}courses c ON c.id=e.course_id LEFT JOIN {$p}instruments i ON i.id=c.instrument_id LEFT JOIN {$p}teachers t ON t.id=e.teacher_id ORDER BY e.id DESC LIMIT 100") ?: array();
        $result = array();
        foreach ($rows as $row) $result[] = $this->summary($row);
        usort($result, static function(object $a, object $b): int {
            $rank = static fn(object $row): int => $row->attention !== 'No attention recorded' ? 0 : 1;
            return ($rank($a) <=> $rank($b)) ?: ((int) $b->id <=> (int) $a->id);
        });
        return $result;
    }

    public function record(int $enrolmentId): ?object {
        $this->requireCapability();
        if ($enrolmentId < 1) return null;
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $row = $wpdb->get_row($wpdb->prepare("SELECT e.*, COALESCE(NULLIF(s.display_name,''),NULLIF(s.first_name,''),s.last_name) student_name, COALESCE(NULLIF(c.name_en,''),c.name_fa) course_name, COALESCE(NULLIF(i.name_en,''),i.name_fa) instrument_name, COALESCE(NULLIF(t.display_name,''),NULLIF(t.english_name,''),t.persian_name) enrolled_teacher_name FROM {$p}enrolments e LEFT JOIN {$p}students s ON s.id=e.student_id LEFT JOIN {$p}courses c ON c.id=e.course_id LEFT JOIN {$p}instruments i ON i.id=c.instrument_id LEFT JOIN {$p}teachers t ON t.id=e.teacher_id WHERE e.id=%d", $enrolmentId));
        return $row ? $this->summary($row) : null;
    }

    /** @return array<int,object> */
    public function classesForEnrolment(int $enrolmentId): array {
        $this->requireCapability();
        if (!current_user_can('dzn_manage_lessons')) return array();
        return (new LessonOperationsReadService())->forEnrolment($enrolmentId);
    }

    private function summary(object $row): object {
        $row->teacher_name = (string) ($row->enrolled_teacher_name ?? '');
        $row->term_label = 'No term recorded';
        $row->class_summary = 'Class details unavailable';
        $row->next_class = 'Class details unavailable';
        $attention = array();

        if (($row->record_model ?? 'legacy_phase1') === 'canonical_student_course_v1') {
            $history = (new EnrolmentRepository())->lifecycleHistory((int) $row->id);
            if (!CanonicalEnrolmentLifecycleValidator::valid($row, $history)) {
                $row->enrolment_status = 'Integrity review needed';
                $attention[] = 'Enrolment lifecycle needs integrity review';
            } else {
                $row->enrolment_status = self::label((string) $row->lifecycle_state);
                $this->assignment($row, $attention);
            }
        } else {
            $row->enrolment_status = self::label((string) ($row->status ?? 'not recorded'));
        }

        $this->terms($row, $attention);
        $classes = $this->classesForEnrolment((int) $row->id);
        if (current_user_can('dzn_manage_lessons')) {
            $row->class_summary = count($classes) . ' class' . (count($classes) === 1 ? '' : 'es');
            $scheduled = array_values(array_filter($classes, static fn(object $class): bool => $class->class_status === 'Scheduled'));
            $row->next_class = $scheduled ? (string) $scheduled[0]->schedule_label : 'No scheduled class';
            foreach ($classes as $class) if (($class->attention ?? 'No attention recorded') !== 'No attention recorded') $attention[] = (string) $class->attention;
        }
        if (current_user_can('dzn_manage_exceptions')) {
            $count = (new OperationalExceptionRepository())->activeCountFor('enrolment', (int) $row->id);
            if ($count > 0) $attention[] = $count . ' active operational exception' . ($count === 1 ? '' : 's');
        }
        $row->attention = $attention ? implode(' · ', array_values(array_unique($attention))) : 'No attention recorded';
        return $row;
    }

    private function assignment(object $row, array &$attention): void {
        $repository = new TeacherAssignmentRepository();
        $assignments = $repository->assignmentsForEnrolment((int) $row->id);
        if (!TeacherAssignmentAssessment::validHistory($repository, (int) $row->id, $assignments)) {
            $row->teacher_name = 'Teacher assignment needs integrity review';
            $attention[] = 'Teacher assignment needs integrity review';
            return;
        }
        $current = $repository->currentForEnrolment((int) $row->id);
        if (!$current) {
            if (TeacherAssignmentAssessment::applicableEnrolment($row)) {
                $row->teacher_name = 'No current teacher assignment';
                $attention[] = 'No current teacher assignment';
            }
            return;
        }
        global $wpdb;
        $teacher = $wpdb->get_row($wpdb->prepare("SELECT COALESCE(NULLIF(display_name,''),NULLIF(english_name,''),persian_name) name FROM {$wpdb->prefix}dzn_teachers WHERE id=%d", (int) $current->teacher_id));
        $row->teacher_name = (string) ($teacher->name ?? 'Assigned teacher');
    }

    private function terms(object $row, array &$attention): void {
        if (($row->record_model ?? 'legacy_phase1') !== 'canonical_student_course_v1') {
            global $wpdb;
            $term = $wpdb->get_row($wpdb->prepare("SELECT sequence_number,status FROM {$wpdb->prefix}dzn_terms WHERE enrolment_id=%d AND archived_at IS NULL ORDER BY sequence_number DESC,id DESC LIMIT 1", (int) $row->id));
            $row->term_label = $term ? 'Term ' . (int) $term->sequence_number . ' · ' . self::label((string) $term->status) : 'No term recorded';
            return;
        }
        if (!current_user_can('dzn_manage_terms')) {
            $row->term_label = 'Term status unavailable (term access required)';
            return;
        }
        try {
            $terms = (new CanonicalTermReadService())->forEnrolment((int) $row->id)['terms'];
            $current = array_values(array_filter($terms, static fn(array $term): bool => $term['lifecycle_state'] === 'current'));
            $row->term_label = $current ? 'Term ' . (int) $current[0]['sequence_number'] . ' · Current' : ($terms ? 'No current term' : 'No term recorded');
            if (TeacherAssignmentAssessment::applicableEnrolment($row) && !$current) $attention[] = 'No current term';
        } catch (\Throwable) {
            $row->term_label = 'Term history needs integrity review';
            $attention[] = 'Term history needs integrity review';
        }
    }

    private function requireCapability(): void {
        if (!current_user_can('dzn_manage_enrolments')) throw new \RuntimeException('Unauthorized');
    }

    private static function label(string $value): string { return ucfirst(str_replace('_', ' ', $value)); }
}
