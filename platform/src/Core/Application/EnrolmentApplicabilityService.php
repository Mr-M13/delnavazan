<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\{
    AcceptedServiceArrangementRepository,
    EnrolmentRepository
};

/** Read-only classification; readiness and conversion authority are out of scope. */
final class EnrolmentApplicabilityService {
    public const NONE = 'none';
    public const CANONICAL_APPLICABLE = 'canonical_applicable';
    public const CANONICAL_CLOSED_HISTORY = 'canonical_closed_history';
    public const LEGACY_REVIEW_REQUIRED = 'legacy_review_required';
    public const ALREADY_LINKED_SOURCE = 'already_linked_source';
    public const DATA_INTEGRITY_CONFLICT = 'data_integrity_conflict';

    private const LEGACY_STATUSES = ['draft', 'active', 'paused', 'ending', 'completed', 'cancelled', 'archived'];
    private const LIFECYCLE_STATES = ['authorised', 'current', 'paused', 'closed'];
    private const APPLICABLE_STATES = ['authorised', 'current', 'paused'];
    private const LINEAGE_MEANINGS = ['successor', 'return_after_closure', 'correction', 'distinct_concurrent_service'];

    public function classify(int $studentId, int $courseId, ?int $acceptedArrangementId = null): string {
        if (!current_user_can('dzn_manage_enrolments')) throw new \RuntimeException('Unauthorized');
        if ($studentId < 1 || $courseId < 1 || ($acceptedArrangementId !== null && $acceptedArrangementId < 1)) {
            throw new \InvalidArgumentException('Positive Student, Course and source identifiers required');
        }

        $enrolments = new EnrolmentRepository();
        $arrangements = new AcceptedServiceArrangementRepository();
        $sourceRows = [];
        if ($acceptedArrangementId !== null) {
            $source = $arrangements->arrangementById($acceptedArrangementId);
            if (!$source || (int) $source->student_id !== $studentId || (int) ($source->course_id ?? 0) !== $courseId) {
                return self::DATA_INTEGRITY_CONFLICT;
            }
            $sourceRows = $enrolments->forAcceptedArrangement($acceptedArrangementId);
            if (count($sourceRows) > 1) return self::DATA_INTEGRITY_CONFLICT;
            if ($sourceRows && ((int) $sourceRows[0]->student_id !== $studentId || (int) $sourceRows[0]->course_id !== $courseId)) {
                return self::DATA_INTEGRITY_CONFLICT;
            }
        }

        $rows = $enrolments->forStudentCourse($studentId, $courseId);
        $legacy = false;
        $canonical = false;
        $applicable = 0;
        $closed = 0;
        foreach ($rows as $row) {
            if ($row->record_model === 'legacy_phase1') {
                if (!$this->validLegacy($row)) return self::DATA_INTEGRITY_CONFLICT;
                $legacy = true;
                continue;
            }
            if ($row->record_model !== 'canonical_student_course_v1' || !$this->validCanonical($row, $arrangements, $enrolments) || !CanonicalEnrolmentLifecycleValidator::valid($row, $enrolments->lifecycleHistory((int)$row->id))) {
                return self::DATA_INTEGRITY_CONFLICT;
            }
            $canonical = true;
            if ($row->lifecycle_state === 'closed') $closed++; else $applicable++;
        }

        if ($legacy && $canonical) return self::DATA_INTEGRITY_CONFLICT;
        if ($applicable > 1) return self::DATA_INTEGRITY_CONFLICT;
        if ($sourceRows) {
            $linked = $sourceRows[0];
            if ($linked->record_model !== 'canonical_student_course_v1' || !$this->validCanonical($linked, $arrangements, $enrolments) || !CanonicalEnrolmentLifecycleValidator::valid($linked, $enrolments->lifecycleHistory((int)$linked->id))) {
                return self::DATA_INTEGRITY_CONFLICT;
            }
            return self::ALREADY_LINKED_SOURCE;
        }
        if ($legacy) return self::LEGACY_REVIEW_REQUIRED;
        if ($applicable === 1) return self::CANONICAL_APPLICABLE;
        if ($closed > 0) return self::CANONICAL_CLOSED_HISTORY;
        return self::NONE;
    }

    private function validLegacy(object $row): bool {
        return in_array((string) $row->status, self::LEGACY_STATUSES, true)
            && $row->accepted_service_arrangement_id === null
            && $row->lifecycle_state === null
            && $row->applicable_slot === null
            && $row->predecessor_enrolment_id === null
            && $row->lineage_meaning === null;
    }

    private function validCanonical(object $row, AcceptedServiceArrangementRepository $arrangements, EnrolmentRepository $enrolments): bool {
        if (!in_array((string) $row->lifecycle_state, self::LIFECYCLE_STATES, true)
            || (int) ($row->accepted_service_arrangement_id ?? 0) < 1
            || $row->archived_at !== null
            || $row->status !== 'canonical') return false;
        $expectedSlot = in_array($row->lifecycle_state, self::APPLICABLE_STATES, true) ? 1 : null;
        if (($row->applicable_slot === null ? null : (int) $row->applicable_slot) !== $expectedSlot) return false;
        if (($row->teacher_id !== null && (int) $row->teacher_id < 1)
            || (($row->predecessor_enrolment_id === null) !== ($row->lineage_meaning === null))) return false;
        $source = $arrangements->arrangementById((int) $row->accepted_service_arrangement_id);
        if (!$source || (int) $source->student_id !== (int) $row->student_id || (int) ($source->course_id ?? 0) !== (int) $row->course_id) return false;
        if ($row->predecessor_enrolment_id !== null) {
            if (!in_array((string) $row->lineage_meaning, self::LINEAGE_MEANINGS, true)) return false;
            $predecessor = $enrolments->find((int) $row->predecessor_enrolment_id);
            if (!$predecessor || (int) $predecessor->id >= (int) $row->id
                || $predecessor->record_model !== 'canonical_student_course_v1'
                || (int) $predecessor->student_id !== (int) $row->student_id
                || (int) $predecessor->course_id !== (int) $row->course_id
                || $predecessor->lifecycle_state !== 'closed'
                || $predecessor->applicable_slot !== null) return false;
        }
        return true;
    }
}
