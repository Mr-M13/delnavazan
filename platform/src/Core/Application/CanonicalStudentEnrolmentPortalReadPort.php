<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Portals\{AuthenticatedPortalReadSubject,StudentEnrolmentPortalReadPort};
use Delnavazan\Platform\Core\Infrastructure\Repository\{CanonicalLessonAuthorityRepository,CourseRepository,EnrolmentRepository,InstrumentRepository,StudentRepository,TeacherAssignmentRepository,TeacherRepository};

/**
 * Student-owned Enrolment read that is usable before Term/Teacher Assignment provisioning.
 *
 * Teacher Assignment and Term are optional for portal visibility: a paid Enrolment is recorded
 * before its funded Term exists, and the Student must still see accurate partial information.
 * Ownership and canonical lifecycle integrity are mandatory and still fail closed.
 */
final class CanonicalStudentEnrolmentPortalReadPort implements StudentEnrolmentPortalReadPort {
    public function __construct(
        private ?TeacherAssignmentRepository $assignments = null,
        private ?EnrolmentRepository $enrolments = null,
        private ?PostIntroFulfilmentReadService $fulfilment = null
    ) {
        $this->assignments ??= new TeacherAssignmentRepository();
        $this->enrolments ??= new EnrolmentRepository();
        $this->fulfilment ??= new PostIntroFulfilmentReadService();
    }

    public function forSubject(AuthenticatedPortalReadSubject $subject, int $enrolmentId): array {
        if ($enrolmentId < 1) throw new \InvalidArgumentException('portal_object_not_found');
        if ($subject->kind === 'teacher') throw new \InvalidArgumentException('portal_object_not_portal_visible');

        $enrolment = (new CanonicalLessonAuthorityRepository())->enrolment($enrolmentId);
        $history = $enrolment ? $this->enrolments->lifecycleHistory($enrolmentId) : array();
        if (!$enrolment || !CanonicalEnrolmentLifecycleValidator::valid($enrolment, $history)) {
            throw new \InvalidArgumentException('portal_upstream_aggregate_invalid');
        }
        if (!$this->isStudentOrGuardian($subject, $enrolment)) {
            throw new \InvalidArgumentException('portal_object_not_owned');
        }

        $assignmentRows = $this->assignments->assignmentsForEnrolment($enrolmentId);
        if (!TeacherAssignmentAssessment::validHistory($this->assignments, $enrolmentId, $assignmentRows)) {
            throw new \InvalidArgumentException('portal_upstream_aggregate_invalid');
        }
        $assignment = $this->assignments->currentForEnrolment($enrolmentId);

        $course = (new CourseRepository())->find((int) $enrolment->course_id);
        $student = (new StudentRepository())->find((int) $enrolment->student_id);
        if (!$course || !$student || $course->archived_at !== null || $student->archived_at !== null
            || trim((string) $course->reference_code) === ''
            || trim((string) $student->reference_code) === ''
            || trim((string) $enrolment->reference_code) === '') {
            throw new \InvalidArgumentException('portal_upstream_aggregate_invalid');
        }

        $teacherReference = '';
        $teacherName = '';
        if ($assignment) {
            $teacher = (new TeacherRepository())->find((int) $assignment->teacher_id);
            if (!$teacher || $teacher->archived_at !== null || trim((string) $teacher->reference_code) === '') {
                throw new \InvalidArgumentException('portal_upstream_aggregate_invalid');
            }
            $teacherReference = (string) $teacher->reference_code;
            $teacherName = trim((string) ($teacher->display_name ?? ''));
            if ($teacherName === '') { $teacherName = trim((string) ($teacher->english_name ?? $teacher->persian_name ?? $teacher->reference_code)); }
        }
        $instrument = (new InstrumentRepository())->find((int) ($course->instrument_id ?? 0));
        $instrumentName = $instrument ? (trim((string) ($instrument->name_fa ?? '')) !== '' ? (string) $instrument->name_fa : (string) ($instrument->name_en ?? '')) : (trim((string) ($course->name_fa ?? '')) !== '' ? (string) $course->name_fa : (string) ($course->name_en ?? ''));

        $termReference = '';
        $termState = 'none';
        $currentTerm = null;
        try {
            $inspection = (new TermApplicabilityAssessment())->inspectForAuthority($enrolmentId);
            $termState = (string) ($inspection['classification'] ?? 'none');
            foreach ((array) ($inspection['terms'] ?? array()) as $term) {
                if ((int) ($term->applicable_slot ?? 0) === 1) {
                    $termReference = trim((string) ($term->reference_code ?? ''));
                    if ($termReference === '') throw new \InvalidArgumentException('portal_upstream_aggregate_invalid');
                    $currentTerm = $term;
                    break;
                }
            }
            if ($termState === TermApplicabilityAssessment::DATA_INTEGRITY_CONFLICT) {
                throw new \InvalidArgumentException('portal_upstream_aggregate_invalid');
            }
        } catch (\InvalidArgumentException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new \InvalidArgumentException('portal_upstream_aggregate_invalid');
        }

        // Replacement-lesson usage is part of the Student's visible Term entitlement. It is read
        // only after the Enrolment has been ownership-proved; unscheduled replacements remain visible
        // as pending so the Student never has to infer them from a missing class row.
        $replacementLessons = array();
        if ($currentTerm && (int) ($currentTerm->id ?? 0) > 0) {
            global $wpdb;
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT l.uid,l.reference_code,l.lifecycle_state,v.uid schedule_version_uid,v.starts_at_utc,v.ends_at_utc
                 FROM {$wpdb->prefix}dzn_lessons l
                 LEFT JOIN {$wpdb->prefix}dzn_canonical_lesson_schedule_versions v ON v.lesson_id=l.id AND v.applicable_slot=1
                 WHERE l.term_id=%d AND l.record_model='canonical_term_lesson_v1' AND l.lesson_type='replacement' AND l.archived_at IS NULL
                 ORDER BY l.id ASC",
                (int) $currentTerm->id
            ));
            if ($wpdb->last_error !== '') throw new \InvalidArgumentException('portal_upstream_aggregate_invalid');
            foreach ((array) $rows as $row) {
                $replacementLessons[] = array(
                    'lesson_uid' => (string) $row->uid,
                    'reference_code' => (string) ($row->reference_code ?? ''),
                    'lifecycle_state' => (string) $row->lifecycle_state,
                    'schedule_version_uid' => (string) ($row->schedule_version_uid ?? ''),
                    'starts_at_utc' => (string) ($row->starts_at_utc ?? ''),
                    'ends_at_utc' => (string) ($row->ends_at_utc ?? ''),
                    'scheduled' => trim((string) ($row->schedule_version_uid ?? '')) !== '',
                );
            }
        }
        $replacementAllowance = $currentTerm ? (int) ($currentTerm->replacement_allowance ?? 0) : 0;
        $replacementUsed = count($replacementLessons);

        $source = $this->assignments->initialSource($enrolmentId);
        $frequency = 0;
        $timezone = '';
        $acceptedAt = (string) $enrolment->created_at;
        if ($source && isset($source['arrangement'])) {
            $frequency = (int) ($source['arrangement']->frequency_per_week ?? 0);
            $timezone = (string) ($source['arrangement']->timezone ?? '');
            $acceptedAt = (string) ($source['arrangement']->accepted_at ?? $acceptedAt);
        }

        $fulfilment = array(
            'fulfilment_state' => 'not_applicable',
            'payment_verified' => false,
            'provisioning_message_code' => null,
        );
        try {
            $status = $this->fulfilment->forEnrolment($enrolmentId);
            $fulfilment = array(
                'fulfilment_state' => (string) ($status['fulfilment_state'] ?? 'not_applicable'),
                'payment_verified' => (bool) ($status['payment_verified'] ?? false),
                'provisioning_message_code' => $status['message_code'] ?? null,
            );
        } catch (\Throwable) {
            // An optional post-intro projection must never take down the Student Portal.
        }

        return array(
            'assignment_uid' => $assignment ? (string) $assignment->uid : '',
            'enrolment_uid' => (string) $enrolment->uid,
            'course_reference' => (string) $course->reference_code,
            'course_name' => trim((string) ($course->name_fa ?? '')) !== '' ? (string) $course->name_fa : (string) ($course->name_en ?? ''),
            'instrument_name' => $instrumentName,
            'teacher_name' => $teacherName,
            'student_name' => trim((string) ($student->display_name ?? '')) !== '' ? (string) $student->display_name : (string) $student->reference_code,
            'term_reference' => $termReference,
            'assignment_state' => $assignment ? (string) $assignment->state : 'pending',
            'accepted_at' => $acceptedAt,
            'student_display_reference' => (string) $student->reference_code,
            'enrolment_reference' => (string) $enrolment->reference_code,
            'lifecycle_state' => (string) $enrolment->lifecycle_state,
            'assigned_teacher_display_reference' => $teacherReference,
            'schedule_summary' => array('frequency_per_week' => $frequency, 'timezone' => $timezone),
            'fulfilment_state' => $fulfilment['fulfilment_state'],
            'payment_verified' => $fulfilment['payment_verified'],
            'provisioning_message_code' => $fulfilment['provisioning_message_code'],
            'term_state' => $termState,
            'replacement_allowance' => $replacementAllowance,
            'replacement_used' => $replacementUsed,
            'replacement_remaining' => max(0, $replacementAllowance - $replacementUsed),
            'replacement_lessons' => $replacementLessons,
        );
    }

    private function isStudentOrGuardian(AuthenticatedPortalReadSubject $subject, object $enrolment): bool {
        if ((int) $subject->principalId !== (int) $enrolment->student_id) return false;
        if ($subject->kind === 'student') {
            global $wpdb;
            return (bool) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}dzn_student_principal_links WHERE student_id=%d AND wordpress_user_id=%d AND status='active' AND revoked_at IS NULL AND active_slot=1 LIMIT 1",
                (int) $subject->principalId,
                (int) $subject->wordpressUserId
            ));
        }
        if ($subject->kind !== 'guardian') return false;
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}dzn_student_acceptance_authority_grants WHERE id=%d AND student_id=%d AND acting_wordpress_user_id=%d AND authority_type='guardian_representative' AND authority_scope=%s AND state='active' AND active_slot=1 AND effective_from<=UTC_TIMESTAMP() AND (effective_until IS NULL OR effective_until>UTC_TIMESTAMP()) LIMIT 1",
            (int) $subject->guardianGrantId,
            (int) $enrolment->student_id,
            (int) $subject->wordpressUserId,
            (string) $subject->guardianGrantScope
        ));
    }
}
