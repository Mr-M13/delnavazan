<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\TeacherAssignmentRepository;
use Delnavazan\Platform\Core\Support\Identifier;

/** Explicit atomic Teacher Assignment lifecycle authority. */
final class TeacherAssignmentService {
    private const MANAGE_CAPABILITY = 'dzn_manage_teacher_assignments';
    private const ACCEPT_CAPABILITY = 'dzn_accept_own_teacher_assignments';
    private const CHANNELS = array('authenticated_platform', 'phone', 'whatsapp', 'email', 'video_call', 'in_person', 'other_verified');

    public function __construct(private ?TeacherAssignmentRepository $repository = null) { $this->repository ??= new TeacherAssignmentRepository(); }

    public function assignInitial(int $enrolmentId, string $idempotencyKey): array {
        $this->requireManager();
        $actor = $this->actor();
        $payloadFacts = array('authority' => 'retained_final_arrangement');
        return $this->execute('initial', $enrolmentId, null, $payloadFacts, $idempotencyKey, function(string $key, string $payload) use ($enrolmentId, $actor): array {
            $source = $this->repository->initialSource($enrolmentId, true);
            if (!$source || !TeacherAssignmentAssessment::validInitialSource($source)) throw new \InvalidArgumentException('source_integrity_conflict');
            $enrolment = $source['enrolment'];
            if (!TeacherAssignmentAssessment::applicableEnrolment($enrolment)) throw new \InvalidArgumentException('enrolment_not_applicable');
            if ($winner = $this->repository->commandForDigest($key)) return $this->replay($winner, $payload, 'initial', $enrolmentId, null, array('authority' => 'retained_final_arrangement'));
            $teacherId = (int) $source['arrangement']->teacher_id;
            $teachers = $this->repository->lockTeachers(array($teacherId));
            $assignments = $this->repository->assignmentsForEnrolment($enrolmentId, true);
            do_action('dzn_phase_2a2j_assignment_locks_held', 'initial', $enrolmentId);
            if (!TeacherAssignmentAssessment::validHistory($this->repository, $enrolmentId, $assignments)) throw new \InvalidArgumentException('data_integrity_conflict');
            if ($assignments) {
                $initial = $assignments[0];
                if ($this->completeResult('initial', $enrolmentId, null, array('authority' => 'retained_final_arrangement'), $initial, false)) {
                    return $this->existing($initial, 'initial');
                }
                throw new \InvalidArgumentException('initial_assignment_already_recorded');
            }
            if (!TeacherAssignmentAssessment::currentTeacher($teachers[$teacherId] ?? null)) throw new \InvalidArgumentException('teacher_not_current');

            $now = gmdate('Y-m-d H:i:s');
            $assignmentId = $this->createAssignment($enrolmentId, $teacherId, 1, null, 'initial_final_arrangement', (int) $source['arrangement']->id, $now, $actor);
            do_action('dzn_phase_2a2j_after_initial_assignment_insert');
            $reference = (string) $source['arrangement']->uid . ':' . (string) $source['version']->source_assent_uid . ':' . (string) $source['version']->source_assent_version;
            $this->repository->insertEvent($this->event(
                $assignmentId, 1, 'initial_assigned', null, 'assigned', null,
                'retained_final_arrangement', 'retained_final_arrangement_and_assent', 'retained_platform_provenance',
                TeacherAssignmentIdempotency::evidenceDigest($reference), (string) $source['arrangement']->accepted_at,
                (int) $source['arrangement']->id, (int) $source['version']->source_assent_snapshot_id, (int) $source['version']->source_assent_id,
                $now, $actor
            ));
            do_action('dzn_phase_2a2j_after_initial_event_insert');
            $this->recordCommand($key, $payload, 'initial', $enrolmentId, $teacherId, $assignmentId, $now, $actor);
            return $this->created($assignmentId, 'initial');
        });
    }

    public function replace(int $enrolmentId, int $newTeacherId, array $agreement, string $idempotencyKey): array {
        if ($newTeacherId < 1) throw new \InvalidArgumentException('Teacher identity required');
        $normalized = $this->normalizeReplacementAgreement($newTeacherId, $agreement);
        $actor = $this->actor();
        return $this->execute('replace', $enrolmentId, $newTeacherId, $normalized['payload'], $idempotencyKey, function(string $key, string $payload) use ($enrolmentId, $newTeacherId, $normalized, $actor): array {
            $enrolment = $this->repository->enrolment($enrolmentId, true);
            if (!TeacherAssignmentAssessment::applicableEnrolment($enrolment)) throw new \InvalidArgumentException('enrolment_not_applicable');
            if ($winner = $this->repository->commandForDigest($key)) return $this->replay($winner, $payload, 'replace', $enrolmentId, $newTeacherId, $normalized['payload']);
            $hint = $this->repository->currentForEnrolment($enrolmentId, false);
            $teacherIds = array($newTeacherId);
            if ($hint) $teacherIds[] = (int) $hint->teacher_id;
            $teachers = $this->repository->lockTeachers($teacherIds);
            $assignments = $this->repository->assignmentsForEnrolment($enrolmentId, true);
            do_action('dzn_phase_2a2j_assignment_locks_held', 'replace', $enrolmentId);
            if (!TeacherAssignmentAssessment::validHistory($this->repository, $enrolmentId, $assignments)) throw new \InvalidArgumentException('data_integrity_conflict');
            $current = $this->repository->currentForEnrolment($enrolmentId, true);
            if (!$current) throw new \InvalidArgumentException('assignment_missing');
            if ((int) $current->teacher_id === $newTeacherId) {
                if ($this->completeResult('replace', $enrolmentId, $newTeacherId, $normalized['payload'], $current, false)) return $this->existing($current, 'replace');
                throw new \InvalidArgumentException('assignment_changed');
            }
            if ((int) $current->id !== $normalized['expected_assignment_id']) throw new \InvalidArgumentException('assignment_changed');
            if (!TeacherAssignmentAssessment::currentTeacher($teachers[$newTeacherId] ?? null)) throw new \InvalidArgumentException('teacher_not_current');
            $this->authorizeReplacementEvidence($normalized['route'], $newTeacherId);
            if ((new \Delnavazan\Platform\Core\Application\CanonicalLessonScheduleGuard())->activeFutureExists('enrolment', $enrolmentId, gmdate('Y-m-d H:i:s'))) throw new \InvalidArgumentException('active_future_schedule_exists');

            $now = gmdate('Y-m-d H:i:s');
            $eventSequence = count($this->repository->events((int) $current->id)) + 1;
            $this->repository->terminate($current, 'replaced', $now, $actor);
            do_action('dzn_phase_2a2j_after_predecessor_mutation');
            $this->repository->insertEvent($this->event(
                (int) $current->id, $eventSequence, 'replaced', 'assigned', 'replaced', null,
                $normalized['route'], $normalized['basis'], $normalized['channel'], $normalized['digest'], $normalized['evidence_at'],
                null, null, null, $now, $actor
            ));
            do_action('dzn_phase_2a2j_after_predecessor_lifecycle_event_insert');
            do_action('dzn_phase_2a2j_after_predecessor_terminated');
            $assignmentId = $this->createAssignment($enrolmentId, $newTeacherId, count($assignments) + 1, (int) $current->id, 'replacement_agreement', null, $now, $actor);
            do_action('dzn_phase_2a2j_after_successor_assignment_insert');
            $this->repository->insertEvent($this->event(
                $assignmentId, 1, 'replacement_assigned', null, 'assigned', (int) $current->id,
                $normalized['route'], $normalized['basis'], $normalized['channel'], $normalized['digest'], $normalized['evidence_at'],
                null, null, null, $now, $actor
            ));
            do_action('dzn_phase_2a2j_after_successor_lifecycle_event_insert');
            do_action('dzn_phase_2a2j_after_replacement_insert');
            $this->recordCommand($key, $payload, 'replace', $enrolmentId, $newTeacherId, $assignmentId, $now, $actor);
            return $this->created($assignmentId, 'replace', (int) $current->id);
        });
    }

    public function end(int $enrolmentId, array $evidence, string $idempotencyKey): array {
        return $this->terminate('end', 'ended', $enrolmentId, $evidence, $idempotencyKey);
    }

    public function cancel(int $enrolmentId, array $evidence, string $idempotencyKey): array {
        return $this->terminate('cancel', 'cancelled', $enrolmentId, $evidence, $idempotencyKey);
    }

    private function terminate(string $operation, string $state, int $enrolmentId, array $evidence, string $idempotencyKey): array {
        $this->requireManager();
        $actor = $this->actor();
        $normalized = $this->normalizeStaffEvidence($evidence);
        return $this->execute($operation, $enrolmentId, null, $normalized['payload'], $idempotencyKey, function(string $key, string $payload) use ($operation, $state, $enrolmentId, $normalized, $actor): array {
            $enrolment = $this->repository->enrolment($enrolmentId, true);
            if (!$enrolment || $enrolment->record_model !== 'canonical_student_course_v1') throw new \InvalidArgumentException('canonical_enrolment_required');
            if ($winner = $this->repository->commandForDigest($key)) return $this->replay($winner, $payload, $operation, $enrolmentId, null, $normalized['payload']);
            $hint = $this->repository->currentForEnrolment($enrolmentId, false);
            if ($hint) $this->repository->lockTeachers(array((int) $hint->teacher_id));
            $assignments = $this->repository->assignmentsForEnrolment($enrolmentId, true);
            do_action('dzn_phase_2a2j_assignment_locks_held', $operation, $enrolmentId);
            if (!TeacherAssignmentAssessment::validHistory($this->repository, $enrolmentId, $assignments)) throw new \InvalidArgumentException('data_integrity_conflict');
            $current = $this->repository->currentForEnrolment($enrolmentId, true);
            if (!$current) {
                $latest = $assignments ? end($assignments) : null;
                if ($latest && $this->completeResult($operation, $enrolmentId, null, $normalized['payload'], $latest, false)) return $this->existing($latest, $operation);
                throw new \InvalidArgumentException('assignment_missing');
            }
            if ((int) $current->id !== $normalized['expected_assignment_id']) throw new \InvalidArgumentException('assignment_changed');
            $now = gmdate('Y-m-d H:i:s');
            $sequence = count($this->repository->events((int) $current->id)) + 1;
            $this->repository->terminate($current, $state, $now, $actor);
            do_action('dzn_phase_2a2j_after_terminal_mutation', $operation);
            $this->repository->insertEvent($this->event(
                (int) $current->id, $sequence, $state, 'assigned', $state, null,
                'authorised_staff', 'authorised_staff_decision', $normalized['channel'], $normalized['digest'], $normalized['evidence_at'],
                null, null, null, $now, $actor, $normalized['reason_code']
            ));
            do_action('dzn_phase_2a2j_after_terminal_event_insert', $operation);
            $this->recordCommand($key, $payload, $operation, $enrolmentId, (int) $current->teacher_id, (int) $current->id, $now, $actor);
            return $this->created((int) $current->id, $operation);
        });
    }

    private function execute(string $operation, int $enrolmentId, ?int $teacherId, array $payloadFacts, string $rawKey, callable $operationBody): array {
        if ($enrolmentId < 1) throw new \InvalidArgumentException('Enrolment identity required');
        $key = TeacherAssignmentIdempotency::keyDigest($rawKey);
        $payload = TeacherAssignmentIdempotency::payloadDigest($operation, $enrolmentId, $teacherId, $payloadFacts);
        if ($command = $this->repository->commandForDigest($key)) return $this->replay($command, $payload, $operation, $enrolmentId, $teacherId, $payloadFacts);
        $this->repository->begin();
        try {
            if ($command = $this->repository->commandForDigest($key)) {
                $result = $this->replay($command, $payload, $operation, $enrolmentId, $teacherId, $payloadFacts);
                $this->repository->commit();
                return $result;
            }
            $result = $operationBody($key, $payload);
            $this->repository->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->repository->rollback();
            $constraint = $this->repository->duplicateConstraint($e);
            if ($constraint === null) throw $e;
            if ($winner = $this->repository->commandForDigest($key)) {
                return $this->replay($winner, $payload, $operation, $enrolmentId, $teacherId, $payloadFacts);
            }
            if ($operation === 'initial' && in_array($constraint, array('enrolment_sequence', 'enrolment_applicable', 'source_arrangement'), true)) {
                $assignment = $this->repository->currentForEnrolment($enrolmentId);
                if ($assignment && $this->completeResult($operation, $enrolmentId, $teacherId, $payloadFacts, $assignment, false)) return $this->existing($assignment, $operation);
            }
            if ($operation === 'replace' && in_array($constraint, array('enrolment_sequence', 'enrolment_applicable', 'predecessor_assignment_id'), true)) {
                $assignment = $this->repository->currentForEnrolment($enrolmentId);
                if ($assignment && $this->completeResult($operation, $enrolmentId, $teacherId, $payloadFacts, $assignment, false)) return $this->existing($assignment, $operation);
            }
            throw $e;
        }
    }

    private function createAssignment(int $enrolmentId, int $teacherId, int $sequence, ?int $predecessorId, string $origin, ?int $sourceArrangementId, string $now, int $actor): int {
        $id = $this->repository->insertAssignment(array(
            'uid' => Identifier::uid(), 'reference_code' => null, 'enrolment_id' => $enrolmentId, 'teacher_id' => $teacherId,
            'assignment_sequence' => $sequence, 'state' => 'assigned', 'applicable_slot' => 1,
            'predecessor_assignment_id' => $predecessorId, 'assignment_origin' => $origin,
            'source_accepted_service_arrangement_id' => $sourceArrangementId,
            'assigned_at' => $now, 'assigned_by' => $actor, 'terminated_at' => null, 'terminated_by' => null,
            'created_at' => $now, 'created_by' => $actor,
        ));
        $this->repository->assignReference($id, Identifier::reference('TAS', $id));
        do_action('dzn_phase_2a2j_after_assignment_insert', $id);
        return $id;
    }

    private function event(int $assignmentId, int $sequence, string $kind, ?string $from, string $to, ?int $predecessorId, string $route, string $basis, string $channel, string $digest, string $evidenceAt, ?int $arrangementId, ?int $snapshotId, ?int $assentId, string $now, int $actor, ?string $reason = null): array {
        return array(
            'uid' => Identifier::uid(), 'assignment_id' => $assignmentId, 'event_sequence' => $sequence,
            'event_kind' => $kind, 'from_state' => $from, 'to_state' => $to, 'predecessor_assignment_id' => $predecessorId,
            'reason_code' => $reason ?? $kind, 'evidence_route' => $route, 'evidence_basis' => $basis,
            'evidence_channel' => $channel, 'evidence_reference_digest' => $digest, 'evidence_at' => $evidenceAt,
            'source_accepted_service_arrangement_id' => $arrangementId, 'source_assent_snapshot_id' => $snapshotId, 'source_assent_id' => $assentId,
            'occurred_at' => $now, 'recorded_at' => $now, 'recorded_by' => $actor, 'created_at' => $now, 'created_by' => $actor,
        );
    }

    private function recordCommand(string $key, string $payload, string $operation, int $enrolmentId, int $teacherId, int $resultId, string $now, int $actor): void {
        $this->repository->insertCommand(array(
            'uid' => Identifier::uid(), 'command_domain' => 'teacher_assignment_v1', 'operation' => $operation,
            'command_key_digest' => $key, 'command_payload_digest' => $payload,
            'enrolment_id' => $enrolmentId, 'teacher_id' => $teacherId, 'result_assignment_id' => $resultId,
            'created_at' => $now, 'created_by' => $actor,
        ));
        do_action('dzn_phase_2a2j_after_command_insert', $operation);
    }

    private function replay(object $command, string $payload, string $operation, int $enrolmentId, ?int $teacherId, array $payloadFacts): array {
        if (!hash_equals((string) $command->command_payload_digest, $payload)) throw new IdempotencyConflictException('Idempotency conflict');
        if ($command->command_domain !== 'teacher_assignment_v1' || $command->operation !== $operation || (int) $command->enrolment_id !== $enrolmentId) {
            throw new \RuntimeException('Contaminated Teacher Assignment result');
        }
        $assignment = $this->repository->assignment((int) $command->result_assignment_id);
        $history = $assignment ? $this->repository->assignmentsForEnrolment($enrolmentId, false) : array();
        if (!$assignment || (int) $assignment->enrolment_id !== $enrolmentId || (int) $assignment->teacher_id !== (int) $command->teacher_id || ($teacherId !== null && (int) $command->teacher_id !== $teacherId) || !TeacherAssignmentAssessment::validHistory($this->repository, $enrolmentId, $history) || !$this->completeResult($operation, $enrolmentId, $teacherId, $payloadFacts, $assignment, true)) {
            throw new \RuntimeException('Contaminated Teacher Assignment result');
        }
        return array('assignment_id' => (int) $assignment->id, 'operation' => $operation, 'created' => false, 'idempotent' => true, 'already_applied' => false);
    }

    /** Prove the whole requested authority; never infer success from a duplicate error. */
    private function completeResult(string $operation, int $enrolmentId, ?int $teacherId, array $facts, object $assignment, bool $replay): bool {
        $history = $this->repository->assignmentsForEnrolment($enrolmentId, false);
        if (!TeacherAssignmentAssessment::validHistory($this->repository, $enrolmentId, $history)) return false;
        if ((int) $assignment->enrolment_id !== $enrolmentId) return false;
        $events = $this->repository->events((int) $assignment->id);
        if ($operation === 'initial') {
            $source = $this->repository->initialSource($enrolmentId, false);
            return $source && TeacherAssignmentAssessment::validInitialSource($source)
                && (int) $assignment->assignment_sequence === 1
                && $assignment->assignment_origin === 'initial_final_arrangement'
                && (int) $assignment->teacher_id === (int) $source['arrangement']->teacher_id
                && (int) $assignment->source_accepted_service_arrangement_id === (int) $source['arrangement']->id
                && ($replay || ($assignment->state === 'assigned' && (int) $assignment->applicable_slot === 1));
        }
        if ($operation === 'replace') {
            $origin = $events[0] ?? null;
            return $teacherId !== null
                && (int) $assignment->teacher_id === $teacherId
                && $assignment->assignment_origin === 'replacement_agreement'
                && (int) $assignment->predecessor_assignment_id === (int) ($facts['expected_assignment_id'] ?? 0)
                && (int) $assignment->assignment_sequence > 1
                && $origin
                && $this->matchingEvidence($origin, $facts, true)
                && ($replay || ($assignment->state === 'assigned' && (int) $assignment->applicable_slot === 1));
        }
        if (in_array($operation, array('end', 'cancel'), true)) {
            $terminal = $events ? end($events) : null;
            $state = $operation === 'end' ? 'ended' : 'cancelled';
            return (int) $assignment->id === (int) ($facts['expected_assignment_id'] ?? 0)
                && $assignment->state === $state
                && $terminal
                && $terminal->event_kind === $state
                && $terminal->to_state === $state
                && $this->matchingEvidence($terminal, $facts, false);
        }
        return false;
    }

    private function matchingEvidence(object $event, array $facts, bool $replacement): bool {
        if ((string) $event->evidence_reference_digest !== (string) ($facts['evidence_reference_digest'] ?? '')
            || (string) $event->evidence_at !== (string) ($facts['evidence_at'] ?? '')
            || (string) $event->evidence_channel !== (string) ($facts['channel'] ?? '')) return false;
        if ($replacement) {
            return (string) $event->evidence_route === (string) ($facts['route'] ?? '')
                && (string) $event->evidence_basis === (string) ($facts['basis'] ?? '');
        }
        return $event->evidence_route === 'authorised_staff'
            && $event->evidence_basis === 'authorised_staff_decision'
            && (string) $event->reason_code === (string) ($facts['reason_code'] ?? '');
    }

    private function normalizeReplacementAgreement(int $teacherId, array $agreement): array {
        $expectedAssignmentId = filter_var($agreement['expected_assignment_id'] ?? null, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
        if ($expectedAssignmentId === false) throw new \InvalidArgumentException('Expected current Teacher Assignment required');
        $route = (string) ($agreement['route'] ?? '');
        if (!in_array($route, array('authenticated_teacher', 'staff_attestation'), true)) throw new \InvalidArgumentException('Assignment-specific Teacher agreement route required');
        if ($route === 'staff_attestation') $this->requireManager();
        $actor = $this->actor();
        if ($route === 'authenticated_teacher' && (!current_user_can(self::ACCEPT_CAPABILITY) || !$this->repository->teacherPrincipalHasAuthority($actor, $teacherId, false))) {
            throw new \RuntimeException('Unauthorized');
        }
        $now = gmdate('Y-m-d H:i:s');
        if ($route === 'authenticated_teacher') {
            $channel = 'authenticated_platform';
            $reference = 'authenticated-teacher:' . $actor . ':target:' . $teacherId;
            $evidenceAt = $this->validTime($agreement['evidence_at'] ?? null, $now);
            $basis = 'authenticated_teacher_acceptance';
        } else {
            $channel = (string) ($agreement['evidence_channel'] ?? '');
            $reference = (string) ($agreement['evidence_reference'] ?? '');
            $evidenceAt = $this->validTime($agreement['evidence_at'] ?? null, $now);
            $basis = 'staff_attested_teacher_agreement';
        }
        if (!in_array($channel, self::CHANNELS, true)) throw new \InvalidArgumentException('Controlled Teacher agreement evidence channel required');
        $digest = TeacherAssignmentIdempotency::evidenceDigest($reference);
        return array('route' => $route, 'basis' => $basis, 'channel' => $channel, 'digest' => $digest, 'evidence_at' => $evidenceAt, 'expected_assignment_id' => (int) $expectedAssignmentId,
            'payload' => array('expected_assignment_id' => (int) $expectedAssignmentId, 'route' => $route, 'basis' => $basis, 'channel' => $channel, 'evidence_reference_digest' => $digest, 'evidence_at' => $evidenceAt));
    }

    private function normalizeStaffEvidence(array $evidence): array {
        $expectedAssignmentId = filter_var($evidence['expected_assignment_id'] ?? null, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
        if ($expectedAssignmentId === false) throw new \InvalidArgumentException('Expected current Teacher Assignment required');
        $channel = (string) ($evidence['evidence_channel'] ?? '');
        if (!in_array($channel, self::CHANNELS, true) || $channel === 'authenticated_platform') throw new \InvalidArgumentException('Controlled staff evidence channel required');
        $reason = (string) ($evidence['reason_code'] ?? '');
        if (!preg_match('/^[a-z0-9_]{3,64}$/D', $reason)) throw new \InvalidArgumentException('Controlled Assignment reason required');
        $now = gmdate('Y-m-d H:i:s');
        $evidenceAt = $this->validTime($evidence['evidence_at'] ?? null, $now);
        $digest = TeacherAssignmentIdempotency::evidenceDigest((string) ($evidence['evidence_reference'] ?? ''));
        return array('channel' => $channel, 'reason_code' => $reason, 'digest' => $digest, 'evidence_at' => $evidenceAt, 'expected_assignment_id' => (int) $expectedAssignmentId,
            'payload' => array('expected_assignment_id' => (int) $expectedAssignmentId, 'channel' => $channel, 'reason_code' => $reason, 'evidence_reference_digest' => $digest, 'evidence_at' => $evidenceAt));
    }

    private function authorizeReplacementEvidence(string $route, int $teacherId): void {
        if ($route === 'staff_attestation') { $this->requireManager(); return; }
        $actor = $this->actor();
        if (!current_user_can(self::ACCEPT_CAPABILITY) || !$this->repository->teacherPrincipalHasAuthority($actor, $teacherId, true)) {
            throw new \RuntimeException('Unauthorized');
        }
    }

    private function validTime(mixed $value, string $now): string {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value) || strtotime($value . ' UTC') === false || $value > $now) {
            throw new \InvalidArgumentException('Valid past-or-present UTC evidence time required');
        }
        return $value;
    }

    private function actor(): int { $actor = get_current_user_id(); if ($actor < 1) throw new \RuntimeException('Teacher Assignment actor is unavailable'); return $actor; }
    private function requireManager(): void { if (!current_user_can(self::MANAGE_CAPABILITY)) throw new \RuntimeException('Unauthorized'); }
    private function created(int $id, string $operation, ?int $predecessor = null): array { return array('assignment_id' => $id, 'predecessor_assignment_id' => $predecessor, 'operation' => $operation, 'created' => true, 'idempotent' => false, 'already_applied' => false); }
    private function existing(object $assignment, string $operation): array { return array('assignment_id' => (int) $assignment->id, 'operation' => $operation, 'created' => false, 'idempotent' => false, 'already_applied' => true); }
}
