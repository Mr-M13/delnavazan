<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\ProposalRepository;
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Creates only Proposal offer authority. It deliberately has no acceptance,
 * arrangement, Student, Enrolment, assignment, Lesson, reservation or payment API.
 */
final class ProposalService {
    private const CAPABILITY = 'dzn_issue_booking_request_proposals';
    private const REPLACEMENT_REASONS = array( 'material_facts_changed', 'assent_renewed', 'operator_correction' );

    public function __construct(
        private ?ProposalRepository $repo = null,
        private ?TeacherAvailabilityAssentService $assents = null
    ) {
        $this->repo ??= new ProposalRepository();
        $this->assents ??= new TeacherAvailabilityAssentService();
    }

    public function issueInitial( int $candidateId, string $arrangementFingerprint, string $idempotencyKey ): array {
        $this->authorize();
        $candidateId = Normalizer::id( $candidateId );
        $arrangementFingerprint = $this->fingerprint( $arrangementFingerprint );
        $keyDigest = ProposalIdempotency::keyDigest( $idempotencyKey );
        $payloadDigest = ProposalIdempotency::payloadDigest( array( 'operation' => 'initial', 'candidate_id' => $candidateId, 'arrangement_fingerprint' => $arrangementFingerprint ) );
        if ( $replay = $this->replay( $keyDigest, $payloadDigest ) ) return $replay;
        $actor = $this->actor();

        return $this->assents->consumeCurrentForFutureProposalIssuance(
            $candidateId,
            $arrangementFingerprint,
            function ( object $assent, object $snapshot, array $context ) use ( $actor, $keyDigest, $payloadDigest ): array {
                return $this->issueLocked( 'initial', null, null, 'initial_issuance', $assent, $snapshot, $context, $actor, $keyDigest, $payloadDigest );
            }
        );
    }

    public function issueReplacement( int $optionId, int $expectedCurrentVersionNumber, string $arrangementFingerprint, string $idempotencyKey, string $reasonCode ): array {
        $this->authorize();
        $optionId = Normalizer::id( $optionId );
        $expectedCurrentVersionNumber = Normalizer::count( $expectedCurrentVersionNumber, 1, PHP_INT_MAX );
        $arrangementFingerprint = $this->fingerprint( $arrangementFingerprint );
        $reasonCode = Normalizer::one( $reasonCode, self::REPLACEMENT_REASONS, 'Proposal replacement reason' );
        $keyDigest = ProposalIdempotency::keyDigest( $idempotencyKey );
        $payloadDigest = ProposalIdempotency::payloadDigest( array( 'operation' => 'replacement', 'option_id' => $optionId, 'expected_current_version' => $expectedCurrentVersionNumber, 'arrangement_fingerprint' => $arrangementFingerprint, 'reason_code' => $reasonCode ) );
        if ( $replay = $this->replay( $keyDigest, $payloadDigest ) ) return $replay;

        // This read locates the Candidate only. No Proposal record is locked before
        // the authoritative Assent consumption path acquires all upstream locks.
        $optionRead = $this->repo->optionForRead( $optionId );
        if ( ! $optionRead ) throw new \InvalidArgumentException( 'Proposal Option not found' );
        $candidateId = (int) $optionRead->candidate_id;
        $actor = $this->actor();

        return $this->assents->consumeCurrentForFutureProposalIssuance(
            $candidateId,
            $arrangementFingerprint,
            function ( object $assent, object $snapshot, array $context ) use ( $optionId, $expectedCurrentVersionNumber, $reasonCode, $actor, $keyDigest, $payloadDigest ): array {
                return $this->issueLocked( 'replacement', $optionId, $expectedCurrentVersionNumber, $reasonCode, $assent, $snapshot, $context, $actor, $keyDigest, $payloadDigest );
            }
        );
    }

    public function exact( string $familyUid, string $optionUid, int $versionNumber ): ?object {
        return $this->repo->exact( $this->uid( $familyUid ), $this->uid( $optionUid ), Normalizer::count( $versionNumber, 1, PHP_INT_MAX ) );
    }

    /** Non-authoritative convenience read. Future acceptance must use an exact trio. */
    public function current( string $familyUid, string $optionUid ): ?object {
        return $this->repo->current( $this->uid( $familyUid ), $this->uid( $optionUid ) );
    }

    private function issueLocked( string $operation, ?int $expectedOptionId, ?int $expectedCurrentNumber, string $reason, object $assent, object $snapshot, array $context, int $actor, string $keyDigest, string $payloadDigest ): array {
        $family = $this->ensureFamily( $context, $actor );
        $option = $this->ensureOption( $family, $context, $actor );
        if ( $expectedOptionId !== null && (int) $option->id !== $expectedOptionId ) throw new \RuntimeException( 'Proposal Option ancestry changed concurrently' );

        if ( $existingCommand = $this->repo->versionForCommand( $keyDigest ) ) {
            if ( ! hash_equals( (string) $existingCommand->command_payload_digest, $payloadDigest ) ) throw new IdempotencyConflictException( 'Idempotency conflict' );
            return $this->responseForVersion( $existingCommand, false, true );
        }

        $current = $this->repo->currentVersionForOption( $option );
        do_action( 'dzn_phase_2a2e_proposal_locks_held' );
        if ( $this->repo->acceptedArrangementForFamily( (int) $family->id ) ) throw new ProposalFamilyAlreadyAcceptedException( 'Proposal Family already accepted' );
        do_action( 'dzn_phase_2a2g_family_finality_checked' );
        if ( $current && (int) $current->proposal_family_id !== (int) $family->id ) throw new \RuntimeException( 'Proposal current-version pointer is inconsistent' );
        if ( $operation === 'initial' && $current && (int) $current->version_number !== 1 ) throw new \InvalidArgumentException( 'Proposal Option already has Version history' );
        if ( $operation === 'replacement' && ( ! $current || (int) $current->version_number !== $expectedCurrentNumber ) ) throw new \RuntimeException( 'Proposal Version changed concurrently' );

        $facts = $this->frozenFacts( $assent, $snapshot, $context );
        $versionFingerprint = ProposalIdempotency::versionFingerprint( $facts );
        if ( $current && hash_equals( (string) $current->version_fingerprint, $versionFingerprint ) ) {
            throw new \InvalidArgumentException( 'Proposal material facts are unchanged' );
        }
        if ( $this->repo->versionForOptionFingerprint( (int) $option->id, $versionFingerprint ) ) throw new \InvalidArgumentException( 'Equivalent Proposal Version already exists' );
        if ( $operation === 'initial' && $current ) throw new \InvalidArgumentException( 'Initial Proposal Version already exists' );

        $number = $current ? (int) $current->version_number + 1 : 1;
        $data = $facts + array(
            'proposal_family_id' => (int) $family->id,
            'proposal_option_id' => (int) $option->id,
            'version_number' => $number,
            'supersedes_proposal_version_id' => $current ? (int) $current->id : null,
            'version_fingerprint' => $versionFingerprint,
            'command_key_digest' => $keyDigest,
            'command_payload_digest' => $payloadDigest,
            'issuance_reason_code' => $reason,
            'issued_at' => $context['now'],
            'issued_by' => $actor,
            'created_at' => $context['now'],
            'created_by' => $actor,
        );
        $version = $this->insertVersion( $data );
        $this->repo->advanceOptionCurrent( $option, $current ? (int) $current->id : null, (int) $version->id, $context['now'], $actor );
        $event = $operation === 'initial' ? 'proposal_version.issued_initial' : 'proposal_version.issued_replacement';
        $this->repo->audit( (int) $version->id, $event, $actor, $reason, 'family=' . $family->id . ';option=' . $option->id . ';version=' . $number . ';fingerprint=' . $versionFingerprint, ProposalIdempotency::auditDigest( 'version:' . $version->uid ), $context['now'] );
        return $this->response( $family, $option, $version, true, false );
    }

    private function ensureFamily( array $context, int $actor ): object {
        $caseId = (int) $context['case']->id; $requestId = (int) $context['request']->id;
        $family = $this->repo->familyForCaseForUpdate( $caseId );
        if ( $family ) {
            if ( (int) $family->booking_request_id !== $requestId ) throw new \RuntimeException( 'Proposal Family ancestry is inconsistent' );
            return $family;
        }
        for ( $attempt = 0; $attempt < 3; $attempt++ ) {
            try {
                $id = $this->repo->insertFamily( array( 'uid' => Identifier::uid(), 'reference_code' => null, 'booking_request_id' => $requestId, 'coordination_case_id' => $caseId, 'created_at' => $context['now'], 'created_by' => $actor ) );
                $this->repo->assignFamilyReference( $id, Identifier::reference( 'PRF', $id ) );
                $family = $this->repo->familyForCaseForUpdate( $caseId );
                if ( ! $family || (int) $family->id !== $id ) throw new \RuntimeException( 'Proposal Family changed concurrently' );
                return $family;
            } catch ( \Throwable $e ) {
                if ( $family = $this->repo->familyForCaseForUpdate( $caseId ) ) {
                    if ( (int) $family->booking_request_id !== $requestId ) throw new \RuntimeException( 'Proposal Family ancestry is inconsistent' );
                    return $family;
                }
                if ( ! $this->repo->isDuplicate( $e ) || $attempt === 2 ) throw $e;
            }
        }
        throw new \RuntimeException( 'Proposal Family UID collision retry limit reached' );
    }

    private function ensureOption( object $family, array $context, int $actor ): object {
        $candidateId = (int) $context['candidate']->id; $teacherId = (int) $context['teacher']->id;
        $option = $this->repo->optionForFamilyCandidateForUpdate( (int) $family->id, $candidateId );
        if ( $option ) {
            if ( (int) $option->teacher_id !== $teacherId ) throw new \RuntimeException( 'Proposal Option Teacher ancestry is inconsistent' );
            return $option;
        }
        $teacherOption = $this->repo->optionForFamilyTeacherForUpdate( (int) $family->id, $teacherId );
        if ( $teacherOption ) {
            if ( (int) $teacherOption->candidate_id !== $candidateId ) throw new \RuntimeException( 'Proposal Option Candidate ancestry is inconsistent' );
            return $teacherOption;
        }
        for ( $attempt = 0; $attempt < 3; $attempt++ ) {
            try {
                $id = $this->repo->insertOption( array( 'uid' => Identifier::uid(), 'reference_code' => null, 'proposal_family_id' => (int) $family->id, 'candidate_id' => $candidateId, 'teacher_id' => $teacherId, 'current_version_id' => null, 'version' => 1, 'created_at' => $context['now'], 'updated_at' => $context['now'], 'created_by' => $actor, 'updated_by' => $actor ) );
                $this->repo->assignOptionReference( $id, Identifier::reference( 'PRO', $id ) );
                $option = $this->repo->optionForFamilyCandidateForUpdate( (int) $family->id, $candidateId );
                if ( ! $option || (int) $option->id !== $id || (int) $option->teacher_id !== $teacherId ) throw new \RuntimeException( 'Proposal Option changed concurrently' );
                return $option;
            } catch ( \Throwable $e ) {
                $option = $this->repo->optionForFamilyCandidateForUpdate( (int) $family->id, $candidateId );
                if ( $option && (int) $option->teacher_id === $teacherId ) return $option;
                if ( ! $this->repo->isDuplicate( $e ) || $attempt === 2 ) throw $e;
            }
        }
        throw new \RuntimeException( 'Proposal Option UID collision retry limit reached' );
    }

    private function insertVersion( array $data ): object {
        for ( $attempt = 0; $attempt < 3; $attempt++ ) {
            try {
                $data['uid'] = Identifier::uid(); $data['reference_code'] = null;
                $id = $this->repo->insertVersion( $data );
                $this->repo->assignVersionReference( $id, Identifier::reference( 'PRV', $id ) );
                $version = $this->repo->versionForRead( $id );
                if ( ! $version ) throw new \RuntimeException( 'Proposal Version persistence verification failed' );
                return $version;
            } catch ( \Throwable $e ) {
                if ( $this->repo->versionForCommand( $data['command_key_digest'] ) || $this->repo->versionForOptionNumber( $data['proposal_option_id'], $data['version_number'] ) || $this->repo->versionForOptionFingerprint( $data['proposal_option_id'], $data['version_fingerprint'] ) ) throw $e;
                if ( ! $this->repo->isDuplicate( $e ) || $attempt === 2 ) throw $e;
            }
        }
        throw new \RuntimeException( 'Proposal Version UID collision retry limit reached' );
    }

    /** Minimal independently intelligible evidence; no Booking Request contact PII. */
    private function frozenFacts( object $assent, object $snapshot, array $context ): array {
        $authorityActor = $assent->attribution_type === 'teacher' ? $assent->teacher_actor_user_id : $assent->attested_by;
        $facts = array(
            'booking_request_id' => (int) $context['request']->id,
            'coordination_case_id' => (int) $context['case']->id,
            'candidate_id' => (int) $context['candidate']->id,
            'teacher_id' => (int) $context['teacher']->id,
            'source_assent_snapshot_id' => (int) $snapshot->id,
            'source_assent_id' => (int) $assent->id,
            'source_assent_uid' => (string) $assent->uid,
            'source_assent_version' => (int) $assent->version,
            'prospective_subject_ref' => (string) $snapshot->prospective_subject_ref,
            'course_id' => $snapshot->course_id === null ? null : (int) $snapshot->course_id,
            'unresolved_course_spec' => $snapshot->unresolved_course_spec,
            'delivery_mode' => (string) $snapshot->delivery_mode,
            'location_scope' => (string) $snapshot->location_scope,
            'frequency_per_week' => (int) $snapshot->frequency_per_week,
            'expected_duration_minutes' => (int) $snapshot->expected_duration_minutes,
            'schedule_constraints' => (string) $snapshot->schedule_constraints,
            'commencement_window_start' => (string) $snapshot->commencement_window_start,
            'commencement_window_end' => (string) $snapshot->commencement_window_end,
            'timezone' => (string) $snapshot->timezone,
            'conditions_code' => $snapshot->conditions_code,
            'arrangement_fingerprint' => (string) $snapshot->arrangement_fingerprint,
            'assent_valid_until' => $assent->valid_until,
            'assent_review_by' => $assent->review_by,
            'assent_recorded_at' => (string) $assent->recorded_at,
            'assent_attribution_type' => (string) $assent->attribution_type,
            'assent_authority_actor_id' => $authorityActor === null ? null : (int) $authorityActor,
            'assent_attribution_basis' => (string) $assent->attribution_basis,
            'assent_evidence_channel' => (string) $assent->evidence_channel,
            'assent_evidence_reference' => (string) $assent->evidence_reference,
            'assent_evidence_at' => (string) $assent->evidence_at,
            'assent_uncertainty_code' => $assent->uncertainty_code,
        );
        if ( $facts['arrangement_fingerprint'] === '' || $facts['source_assent_uid'] === '' || $facts['assent_authority_actor_id'] === null ) throw new \RuntimeException( 'Proposal historical authority is incomplete' );
        return $facts;
    }

    private function replay( string $keyDigest, string $payloadDigest ): ?array {
        $version = $this->repo->versionForCommand( $keyDigest );
        if ( ! $version ) return null;
        if ( ! hash_equals( (string) $version->command_payload_digest, $payloadDigest ) ) throw new IdempotencyConflictException( 'Idempotency conflict' );
        return $this->responseForVersion( $version, false, true );
    }

    private function responseForVersion( object $version, bool $created, bool $idempotent ): array {
        $option = $this->repo->optionForRead( (int) $version->proposal_option_id );
        $family = $option ? $this->repo->familyForRead( (int) $option->proposal_family_id ) : null;
        if ( ! $family || ! $option || (int) $version->proposal_family_id !== (int) $family->id ) throw new \RuntimeException( 'Proposal lineage is inconsistent' );
        return $this->response( $family, $option, $version, $created, $idempotent );
    }

    private function response( object $family, object $option, object $version, bool $created, bool $idempotent ): array {
        return array( 'family_id' => (int) $family->id, 'family_uid' => (string) $family->uid, 'option_id' => (int) $option->id, 'option_uid' => (string) $option->uid, 'version_id' => (int) $version->id, 'version_uid' => (string) $version->uid, 'version_number' => (int) $version->version_number, 'created' => $created, 'idempotent' => $idempotent );
    }

    private function authorize(): void { if ( ! current_user_can( self::CAPABILITY ) ) throw new \RuntimeException( 'Unauthorized' ); }
    private function actor(): int { $actor = get_current_user_id(); if ( $actor < 1 ) throw new \RuntimeException( 'Proposal actor is unavailable' ); return $actor; }
    private function fingerprint( string $value ): string { if ( ! preg_match( '/^[a-f0-9]{64}$/D', $value ) ) throw new \InvalidArgumentException( 'Arrangement fingerprint required' ); return $value; }
    private function uid( string $value ): string { if ( ! preg_match( '/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D', $value ) ) throw new \InvalidArgumentException( 'Proposal UID required' ); return $value; }
}
