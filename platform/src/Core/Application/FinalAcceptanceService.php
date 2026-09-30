<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\AcceptedServiceArrangementRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\BookingRequestRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\CoordinationCaseRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\ProposalRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\StudentIdentityAuthorityRepository;
use Delnavazan\Platform\Core\Support\Identifier;

/** Creates one immutable, PII-free Accepted Service Arrangement from current canonical authority. */
final class FinalAcceptanceService {
    private const CAPABILITY = 'dzn_finalize_service_arrangements';
    private const CHANNELS = array('in_person', 'phone', 'email_reference', 'message_reference', 'other_reference');
    private const CLOSED_CASES = array('withdrawn', 'declined', 'unable_to_arrange', 'abandoned');

    public function __construct(
        private ?BookingRequestRepository $transactions = null,
        private ?CoordinationCaseRepository $cases = null,
        private ?ProposalRepository $proposals = null,
        private ?StudentIdentityAuthorityRepository $authority = null,
        private ?AcceptedServiceArrangementRepository $arrangements = null
    ) {
        $this->transactions ??= new BookingRequestRepository();
        $this->cases ??= new CoordinationCaseRepository();
        $this->proposals ??= new ProposalRepository();
        $this->authority ??= new StudentIdentityAuthorityRepository();
        $this->arrangements ??= new AcceptedServiceArrangementRepository();
    }

    public function accept(
        int $bookingRequestId,
        int $coordinationCaseId,
        string $familyUid,
        string $optionUid,
        int $versionNumber,
        string $provisionalEventUid,
        int $studentId,
        int $acceptingPrincipalId,
        string $confirmation,
        string $confirmationChannel,
        string $confirmedAt,
        string $idempotencyKey
    ): array {
        $this->authorize();
        $actor = get_current_user_id();
        if ($actor < 1) throw new \RuntimeException('Final-acceptance actor is unavailable');
        foreach (array($familyUid, $optionUid, $provisionalEventUid) as $uid) $this->uid($uid);
        foreach (array($bookingRequestId, $coordinationCaseId, $versionNumber, $studentId, $acceptingPrincipalId) as $id) if ($id < 1) throw new \InvalidArgumentException('Exact final-acceptance identifier required');
        if ($confirmation !== 'affirmed') throw new \InvalidArgumentException('Fresh affirmed confirmation required');
        if (!in_array($confirmationChannel, self::CHANNELS, true)) throw new \InvalidArgumentException('Closed confirmation channel required');
        $confirmedAt = $this->utc($confirmedAt);

        $keyDigest = FinalAcceptanceIdempotency::keyDigest($idempotencyKey);
        $payloadDigest = FinalAcceptanceIdempotency::payloadDigest(array(
            'operation' => 'final_acceptance',
            'booking_request_id' => $bookingRequestId,
            'coordination_case_id' => $coordinationCaseId,
            'family_uid' => $familyUid,
            'option_uid' => $optionUid,
            'version_number' => $versionNumber,
            'provisional_event_uid' => $provisionalEventUid,
            'student_id' => $studentId,
            'accepting_principal_id' => $acceptingPrincipalId,
            'confirmation' => 'affirmed',
            'confirmation_channel' => $confirmationChannel,
            'confirmed_at' => $confirmedAt,
        ));
        if ($replay = $this->arrangements->arrangementForCommand($keyDigest)) return $this->replay($replay, $payloadDigest);

        $this->transactions->begin();
        try {
            $result = $this->acceptLocked($bookingRequestId, $coordinationCaseId, $familyUid, $optionUid, $versionNumber, $provisionalEventUid, $studentId, $acceptingPrincipalId, $confirmationChannel, $confirmedAt, $actor, $keyDigest, $payloadDigest);
            $this->transactions->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->transactions->rollback();
            if ($this->arrangements->isDuplicate($e)) {
                $winner = $this->arrangements->arrangementForCommand($keyDigest);
                if ($winner) return $this->replay($winner, $payloadDigest);
                $version = $this->proposals->exact($familyUid, $optionUid, $versionNumber);
                if ($version && $this->arrangements->arrangementForFamily((int) $version->proposal_family_id)) throw new ProposalFamilyAlreadyAcceptedException('Proposal Family already accepted');
            }
            throw $e;
        }
    }

    private function acceptLocked(int $requestId, int $caseId, string $familyUid, string $optionUid, int $versionNumber, string $provisionalUid, int $studentId, int $principalId, string $channel, string $confirmedAt, int $actor, string $keyDigest, string $payloadDigest): array {
        $now = gmdate('Y-m-d H:i:s');
        $request = $this->cases->requestForUpdate($requestId);
        if (!$request || $request->lifecycle_status !== 'submitted' || $request->resolution_state !== 'unresolved' || $request->privacy_erased_at !== null || (int) $request->student_id !== $studentId) throw new \InvalidArgumentException('Booking Request is unavailable for final acceptance');
        $case = $this->cases->caseForUpdate($caseId);
        if (!$case || (int) $case->booking_request_id !== $requestId || in_array($case->state, self::CLOSED_CASES, true)) throw new \InvalidArgumentException('Coordination Case is unavailable for final acceptance');
        $family = $this->proposals->familyForCaseForUpdate($caseId);
        if (!$family || (string) $family->uid !== $familyUid || (int) $family->booking_request_id !== $requestId) throw new \InvalidArgumentException('Exact Proposal Family is unavailable');

        $options = $this->arrangements->familyOptionsForUpdate((int) $family->id);
        $versions = $this->arrangements->currentVersionsForOptionsForUpdate((int) $family->id);
        if (!$options || count($options) !== count($versions)) throw new \RuntimeException('Proposal Family current-Version lineage is incomplete');
        $selectedOption = $this->rowByUid($options, $optionUid);
        $selectedVersion = $selectedOption ? $this->versionForOption($versions, (int) $selectedOption->id) : null;
        if (!$selectedOption || !$selectedVersion || (int) $selectedVersion->version_number !== $versionNumber || (int) $selectedOption->current_version_id !== (int) $selectedVersion->id) throw new \InvalidArgumentException('Exact current Proposal Version is unavailable');

        $provisional = $this->arrangements->provisionalForUidForUpdate($provisionalUid);
        if (!$provisional || $provisional->event_kind !== 'accepted_pending_conditions' || $provisional->accepting_subject_state !== 'authority_unresolved' || (int) $provisional->booking_request_id !== $requestId || (int) $provisional->coordination_case_id !== $caseId || (int) $provisional->proposal_family_id !== (int) $family->id || (int) $provisional->proposal_option_id !== (int) $selectedOption->id || (int) $provisional->proposal_version_id !== (int) $selectedVersion->id) throw new \InvalidArgumentException('Exact provisional acceptance evidence is unavailable');

        // The already-locked Proposal Family is the serialization root. A
        // FOR UPDATE absence probe on either child table would take a gap lock
        // and can deadlock unrelated Families before authority validation.
        $existing = $this->arrangements->arrangementForFamily((int) $family->id);
        do_action('dzn_phase_2a2g_proposal_locks_held');
        if ($existing) {
            if (hash_equals((string) $existing->command_key_digest, $keyDigest)) return $this->replay($existing, $payloadDigest);
            throw new ProposalFamilyAlreadyAcceptedException('Proposal Family already accepted');
        }

        $student = $this->authority->studentForUpdate($studentId);
        if (!$student || $student->status !== 'active' || $student->archived_at !== null) throw new \InvalidArgumentException('Student is not active');
        $resolution = $this->authority->resolutionByIdForUpdate((int) $request->current_identity_resolution_id);
        if (!$resolution || $resolution->outcome !== 'resolved' || (int) $resolution->booking_request_id !== $requestId || (int) $resolution->student_id !== $studentId) throw new \InvalidArgumentException('Student identity resolution is no longer current');
        $capacity = $this->authority->capacityByIdForUpdate((int) $student->current_acceptance_capacity_classification_id);
        if (!$capacity || (int) $capacity->student_id !== $studentId || !in_array($capacity->classification, array('adult', 'minor'), true)) throw new \InvalidArgumentException('Student acceptance capacity is unresolved');
        if (!$this->authority->wordpressUserForUpdate($principalId)) throw new \InvalidArgumentException('Accepting WordPress principal does not exist');
        $principals = $this->authority->lockPrincipalsForAuthority($studentId, array($principalId));
        $grants = $this->authority->lockGrantsForAuthority($studentId, array($principalId));
        do_action('dzn_phase_2a2g_authority_locks_held');

        $principal = $this->activePrincipal($principals, $studentId, $principalId);
        $grant = $this->activeGuardian($grants, $studentId, $principalId, $now);
        if ($capacity->classification === 'adult') {
            if (!$principal) throw new \InvalidArgumentException('Adult self authority is no longer current');
            $route = 'adult_self'; $grant = null;
        } else {
            if (!$grant) throw new \InvalidArgumentException('Guardian representative authority is no longer current');
            $route = 'guardian_representative'; $principal = null;
        }

        $data = $this->arrangementData($request, $case, $family, $selectedOption, $selectedVersion, $provisional, $student, $resolution, $capacity, $principal, $grant, $route, $principalId, $channel, $confirmedAt, $actor, $now, $keyDigest, $payloadDigest);
        $id = $this->arrangements->insertArrangement($data);
        $this->arrangements->assignArrangementReference($id, Identifier::reference('ASA', $id));
        foreach ($options as $option) {
            $version = $this->versionForOption($versions, (int) $option->id);
            if (!$version) throw new \RuntimeException('Proposal Option current Version disappeared');
            $outcome = (int) $option->id === (int) $selectedOption->id ? 'accepted' : 'closed_competing';
            $this->arrangements->insertOutcome(array('uid' => Identifier::uid(), 'proposal_family_id' => (int) $family->id, 'proposal_option_id' => (int) $option->id, 'proposal_version_id' => (int) $version->id, 'accepted_service_arrangement_id' => $id, 'outcome' => $outcome, 'version_uid' => (string) $version->uid, 'version_number' => (int) $version->version_number, 'version_fingerprint' => (string) $version->version_fingerprint, 'recorded_at' => $now, 'recorded_by' => $actor, 'created_at' => $now, 'created_by' => $actor));
        }
        $this->arrangements->audit($id, $actor, 'family=' . $family->id . ';option=' . $selectedOption->id . ';version=' . $selectedVersion->version_number . ';student=' . $studentId . ';authority=' . $route, hash_hmac('sha256', 'final-acceptance-audit:' . $id, wp_salt('dzn_final_acceptance_audit')), $now);
        $stored = $this->arrangements->arrangementById($id);
        if (!$stored) throw new \RuntimeException('Accepted Service Arrangement persistence verification failed');
        return $this->response($stored, true, false);
    }

    private function arrangementData(object $request, object $case, object $family, object $option, object $version, object $provisional, object $student, object $resolution, object $capacity, ?object $principal, ?object $grant, string $route, int $principalId, string $channel, string $confirmedAt, int $actor, string $now, string $keyDigest, string $payloadDigest): array {
        return array(
            'uid' => Identifier::uid(), 'reference_code' => null,
            'booking_request_id' => (int) $request->id, 'coordination_case_id' => (int) $case->id,
            'proposal_family_id' => (int) $family->id, 'proposal_option_id' => (int) $option->id,
            'proposal_version_id' => (int) $version->id, 'provisional_acceptance_event_id' => (int) $provisional->id,
            'student_id' => (int) $student->id, 'teacher_id' => (int) $version->teacher_id,
            'family_uid' => (string) $family->uid, 'option_uid' => (string) $option->uid,
            'version_uid' => (string) $version->uid, 'version_number' => (int) $version->version_number,
            'version_fingerprint' => (string) $version->version_fingerprint,
            'prospective_subject_ref' => (string) $version->prospective_subject_ref,
            'course_id' => $version->course_id === null ? null : (int) $version->course_id,
            'unresolved_course_spec' => $version->unresolved_course_spec,
            'delivery_mode' => (string) $version->delivery_mode, 'location_scope' => (string) $version->location_scope,
            'frequency_per_week' => (int) $version->frequency_per_week, 'expected_duration_minutes' => (int) $version->expected_duration_minutes,
            'schedule_constraints' => (string) $version->schedule_constraints,
            'commencement_window_start' => (string) $version->commencement_window_start, 'commencement_window_end' => (string) $version->commencement_window_end,
            'timezone' => (string) $version->timezone, 'conditions_code' => $version->conditions_code,
            'arrangement_fingerprint' => (string) $version->arrangement_fingerprint,
            'identity_resolution_event_id' => (int) $resolution->id,
            'identity_resolution_sequence' => (int) $resolution->resolution_sequence,
            'capacity_classification_id' => (int) $capacity->id, 'capacity_classification' => (string) $capacity->classification,
            'authority_route' => $route, 'accepting_wordpress_user_id' => $principalId,
            'principal_link_id' => $principal ? (int) $principal->id : null, 'principal_link_version' => $principal ? (int) $principal->version : null,
            'guardian_grant_id' => $grant ? (int) $grant->id : null, 'guardian_grant_version' => $grant ? (int) $grant->version : null,
            'confirmation_value' => 'affirmed', 'confirmation_channel' => $channel,
            'confirmation_evidence_reference' => hash_hmac('sha256', 'final-confirmation:' . Identifier::uid(), wp_salt('dzn_final_acceptance_evidence')),
            'confirmed_at' => $confirmedAt, 'accepted_at' => $now, 'accepted_by' => $actor,
            'command_key_digest' => $keyDigest, 'command_payload_digest' => $payloadDigest,
            'created_at' => $now, 'created_by' => $actor,
        );
    }

    private function activePrincipal(array $rows, int $studentId, int $userId): ?object { foreach ($rows as $row) if ((int) $row->student_id === $studentId && (int) $row->wordpress_user_id === $userId && $row->status === 'active' && (int) $row->active_slot === 1) return $row; return null; }
    private function activeGuardian(array $rows, int $studentId, int $userId, string $now): ?object { foreach ($rows as $row) if ((int) $row->student_id === $studentId && (int) $row->acting_wordpress_user_id === $userId && $row->authority_type === 'guardian_representative' && $row->authority_scope === 'service_acceptance' && $row->state === 'active' && (int) $row->active_slot === 1 && $row->effective_from <= $now && ($row->effective_until === null || $row->effective_until > $now)) return $row; return null; }
    private function rowByUid(array $rows, string $uid): ?object { foreach ($rows as $row) if ((string) $row->uid === $uid) return $row; return null; }
    private function versionForOption(array $rows, int $optionId): ?object { foreach ($rows as $row) if ((int) $row->proposal_option_id === $optionId) return $row; return null; }
    private function replay(object $arrangement, string $payloadDigest): array { if (!hash_equals((string) $arrangement->command_payload_digest, $payloadDigest)) throw new IdempotencyConflictException('Idempotency conflict'); return $this->response($arrangement, false, true); }
    private function response(object $arrangement, bool $created, bool $idempotent): array { return array('arrangement_id' => (int) $arrangement->id, 'arrangement_uid' => (string) $arrangement->uid, 'reference_code' => (string) $arrangement->reference_code, 'created' => $created, 'idempotent' => $idempotent); }
    private function authorize(): void { if (!current_user_can(self::CAPABILITY)) throw new \RuntimeException('Unauthorized'); }
    private function uid(string $value): void { if (!preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D', $value)) throw new \InvalidArgumentException('Exact immutable UID required'); }
    private function utc(string $value): string { $zone = new \DateTimeZone('UTC'); $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $zone); $errors = \DateTimeImmutable::getLastErrors(); if (!$parsed || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $parsed->format('Y-m-d H:i:s') !== $value) throw new \InvalidArgumentException('Valid UTC confirmation timestamp required'); return $value; }
}
