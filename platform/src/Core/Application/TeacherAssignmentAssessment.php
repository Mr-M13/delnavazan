<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\TeacherAssignmentRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\EnrolmentConversionRepository;

/** Pure structural checks for Assignment readiness and replay integrity. */
final class TeacherAssignmentAssessment {
    private const APPLICABLE_ENROLMENT_STATES = array('authorised', 'current', 'paused');

    public static function initial(TeacherAssignmentRepository $repository, int $enrolmentId): string {
        $source = $repository->initialSource($enrolmentId, false);
        if (!$source || !self::validInitialSource($source)) return 'source_integrity_conflict';
        $enrolment = $source['enrolment'];
        if (!self::applicableEnrolment($enrolment)) return 'enrolment_not_applicable';
        $assignments = $repository->assignmentsForEnrolment($enrolmentId, false);
        if (!self::validHistory($repository, $enrolmentId, $assignments)) return 'data_integrity_conflict';
        if ($repository->currentForEnrolment($enrolmentId)) return 'already_assigned';
        if ($assignments) return 'initial_assignment_already_recorded';
        $teacher = $repository->teacher((int) $source['arrangement']->teacher_id);
        return self::currentTeacher($teacher) ? 'ready' : 'teacher_not_current';
    }

    public static function replacement(TeacherAssignmentRepository $repository, int $enrolmentId, int $teacherId): string {
        $enrolment = $repository->enrolment($enrolmentId);
        if (!self::applicableEnrolment($enrolment)) return 'enrolment_not_applicable';
        $assignments = $repository->assignmentsForEnrolment($enrolmentId, false);
        if (!self::validHistory($repository, $enrolmentId, $assignments)) return 'data_integrity_conflict';
        $current = $repository->currentForEnrolment($enrolmentId);
        if (!$current) return 'assignment_missing';
        if ((int) $current->teacher_id === $teacherId) return 'already_assigned';
        return self::currentTeacher($repository->teacher($teacherId)) ? 'ready' : 'teacher_not_current';
    }

    public static function applicableEnrolment(?object $enrolment): bool {
        return $enrolment
            && $enrolment->record_model === 'canonical_student_course_v1'
            && $enrolment->status === 'canonical'
            && $enrolment->archived_at === null
            && in_array((string) $enrolment->lifecycle_state, self::APPLICABLE_ENROLMENT_STATES, true)
            && (int) $enrolment->applicable_slot === 1;
    }

    public static function currentTeacher(?object $teacher): bool {
        return $teacher && $teacher->status === 'active' && $teacher->archived_at === null;
    }

    public static function validInitialSource(array $source): bool {
        foreach (array('enrolment', 'arrangement', 'version', 'snapshot', 'assent') as $key) if (!isset($source[$key]) || !is_object($source[$key])) return false;
        $e = $source['enrolment']; $a = $source['arrangement']; $v = $source['version']; $s = $source['snapshot']; $x = $source['assent'];
        $conversion = new EnrolmentConversionRepository();
        $graph = $conversion->sourceGraphForRead((int) $a->id);
        $command = $conversion->commandForSource((int) $a->id);
        $lifecycle = $conversion->lifecycleForEnrolment((int) $e->id);
        if (!$graph || !EnrolmentConversionAssessment::validExistingResult($conversion, $graph, $e, $lifecycle, $command, EnrolmentConversionIdempotency::payloadDigest((int) $a->id))) return false;
        return (int) $e->accepted_service_arrangement_id === (int) $a->id
            && (int) $e->student_id === (int) $a->student_id
            && (int) $e->course_id === (int) $a->course_id
            && (int) $e->teacher_id === (int) $a->teacher_id
            && (int) $a->proposal_version_id === (int) $v->id
            && (int) $a->teacher_id === (int) $v->teacher_id
            && (string) $a->version_uid === (string) $v->uid
            && (string) $a->version_fingerprint === (string) $v->version_fingerprint
            && (int) $v->source_assent_snapshot_id === (int) $s->id
            && (int) $v->source_assent_id === (int) $x->id
            && (int) $v->teacher_id === (int) $s->teacher_id
            && (int) $v->teacher_id === (int) $x->teacher_id
            && (int) $x->snapshot_id === (int) $s->id
            && (string) $v->source_assent_uid === (string) $x->uid
            && (int) $v->source_assent_version > 0
            && (int) $x->version >= (int) $v->source_assent_version
            && (string) $v->arrangement_fingerprint === (string) $s->arrangement_fingerprint;
    }

    public static function validHistory(TeacherAssignmentRepository $repository, int $enrolmentId, array $assignments): bool {
        $expectedSequence = 1; $current = 0; $previousAssignment = null;
        foreach ($assignments as $assignment) {
            if ((int) $assignment->enrolment_id !== $enrolmentId || (int) $assignment->assignment_sequence !== $expectedSequence++) return false;
            if (preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D', (string) $assignment->uid) !== 1 || !self::utc($assignment->assigned_at) || (int) $assignment->assigned_by < 1 || !self::utc($assignment->created_at) || (int) $assignment->created_by < 1) return false;
            if (!in_array((string) $assignment->state, array('assigned', 'replaced', 'ended', 'cancelled'), true)) return false;
            $slot = $assignment->state === 'assigned' ? 1 : null;
            if (($assignment->applicable_slot === null ? null : (int) $assignment->applicable_slot) !== $slot) return false;
            if ($assignment->state === 'assigned' && ($assignment->terminated_at !== null || $assignment->terminated_by !== null)) return false;
            if ($assignment->state !== 'assigned' && (!self::utc($assignment->terminated_at) || (string) $assignment->terminated_at < (string) $assignment->assigned_at || (int) $assignment->terminated_by < 1)) return false;
            if ($assignment->state === 'assigned') $current++;
            if ($previousAssignment === null) {
                if ($assignment->assignment_origin !== 'initial_final_arrangement' || $assignment->predecessor_assignment_id !== null || (int) ($assignment->source_accepted_service_arrangement_id ?? 0) < 1) return false;
                $source = $repository->initialSource($enrolmentId, false);
                $events = $repository->events((int) $assignment->id);
                if (!$source || !self::validInitialSource($source) || !$events || (int) $events[0]->source_accepted_service_arrangement_id !== (int) $source['arrangement']->id || (int) $events[0]->source_assent_snapshot_id !== (int) $source['version']->source_assent_snapshot_id || (int) $events[0]->source_assent_id !== (int) $source['version']->source_assent_id) return false;
                $reference = (string) $source['arrangement']->uid . ':' . (string) $source['version']->source_assent_uid . ':' . (string) $source['version']->source_assent_version;
                if (!hash_equals(TeacherAssignmentIdempotency::evidenceDigest($reference), (string) $events[0]->evidence_reference_digest)) return false;
            } else {
                if ($assignment->assignment_origin !== 'replacement_agreement' || (int) $assignment->predecessor_assignment_id !== (int) $previousAssignment->id || $assignment->source_accepted_service_arrangement_id !== null || $previousAssignment->state !== 'replaced') return false;
                $predecessorEvents = $repository->events((int) $previousAssignment->id); $successorEvents = $repository->events((int) $assignment->id);
                $terminal = $predecessorEvents ? end($predecessorEvents) : null; $origin = $successorEvents[0] ?? null;
                if (!$terminal || !$origin || $terminal->event_kind !== 'replaced' || (int) $origin->predecessor_assignment_id !== (int) $previousAssignment->id) return false;
                foreach (array('evidence_route', 'evidence_basis', 'evidence_channel', 'evidence_reference_digest', 'evidence_at', 'occurred_at', 'recorded_at', 'recorded_by') as $field) if ((string) $terminal->{$field} !== (string) $origin->{$field}) return false;
            }
            $assignmentEvents = $repository->events((int) $assignment->id);
            if (!self::validEvents($assignmentEvents, $assignment) || (string) $assignmentEvents[0]->occurred_at !== (string) $assignment->assigned_at) return false;
            if ($assignment->state !== 'assigned' && (string) end($assignmentEvents)->occurred_at !== (string) $assignment->terminated_at) return false;
            $previousAssignment = $assignment;
        }
        $latest = $assignments ? end($assignments) : null;
        return $current <= 1 && (!$latest || $latest->state !== 'replaced');
    }

    private static function validEvents(array $events, object $assignment): bool {
        if (!$events) return false;
        $previous = null;
        foreach ($events as $index => $event) {
            if ((int) $event->assignment_id !== (int) $assignment->id || (int) $event->event_sequence !== $index + 1) return false;
            if ($index === 0) {
                $expectedKind = $assignment->assignment_origin === 'initial_final_arrangement' ? 'initial_assigned' : 'replacement_assigned';
                if ($event->from_state !== null || $event->event_kind !== $expectedKind || $event->to_state !== 'assigned') return false;
                if (($event->predecessor_assignment_id === null ? null : (int) $event->predecessor_assignment_id) !== ($assignment->predecessor_assignment_id === null ? null : (int) $assignment->predecessor_assignment_id)) return false;
                if ($expectedKind === 'initial_assigned' && ((int) $event->source_accepted_service_arrangement_id !== (int) $assignment->source_accepted_service_arrangement_id || (int) $event->source_assent_snapshot_id < 1 || (int) $event->source_assent_id < 1 || $event->evidence_route !== 'retained_final_arrangement')) return false;
                if ($expectedKind === 'initial_assigned' && ($event->evidence_basis !== 'retained_final_arrangement_and_assent' || $event->evidence_channel !== 'retained_platform_provenance')) return false;
                if ($expectedKind === 'replacement_assigned' && !self::validReplacementEvidence($event)) return false;
            } elseif ((string) $event->from_state !== $previous || $event->event_kind !== $event->to_state) return false;
            if ($index > 0 && $event->to_state === 'replaced' && !self::validReplacementEvidence($event)) return false;
            if ($index > 0 && in_array((string) $event->to_state, array('ended', 'cancelled'), true) && ($event->evidence_route !== 'authorised_staff' || $event->evidence_basis !== 'authorised_staff_decision')) return false;
            if (!preg_match('/^[a-f0-9]{64}$/D', (string) $event->evidence_reference_digest) || !self::utc($event->evidence_at) || !self::utc($event->occurred_at) || !self::utc($event->recorded_at) || !self::utc($event->created_at) || (string) $event->evidence_at > (string) $event->recorded_at || (int) $event->recorded_by < 1 || (int) $event->created_by < 1) return false;
            $previous = (string) $event->to_state;
        }
        $expectedEvents = $assignment->state === 'assigned' ? 1 : 2;
        return count($events) === $expectedEvents && $previous === (string) $assignment->state;
    }

    private static function utc(mixed $value): bool {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value) === 1 && strtotime($value . ' UTC') !== false;
    }

    private static function validReplacementEvidence(object $event): bool {
        if ($event->evidence_route === 'authenticated_teacher') return $event->evidence_basis === 'authenticated_teacher_acceptance' && $event->evidence_channel === 'authenticated_platform';
        return $event->evidence_route === 'staff_attestation' && $event->evidence_basis === 'staff_attested_teacher_agreement' && in_array((string) $event->evidence_channel, array('phone', 'whatsapp', 'email', 'video_call', 'in_person', 'other_verified'), true);
    }
}
