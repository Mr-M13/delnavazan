<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\TeacherAvailabilityAssentRepository;
use Delnavazan\Platform\Core\Support\Identifier;

/** Bounded authority: records only a Teacher's time-bounded willingness for frozen pre-issue facts. */
final class TeacherAvailabilityAssentService {
    private const CLOSED_CASES = array( 'withdrawn', 'declined', 'unable_to_arrange', 'abandoned' );
    private const CLOSED_CANDIDATES = array( 'not_available', 'not_suitable', 'withdrawn_from_consideration', 'superseded', 'closed' );
    private const LOCATION_SCOPES = array( 'not_applicable', 'teacher_location', 'student_location', 'location_to_be_agreed' );
    private const CONDITIONS = array( 'none', 'subject_to_later_assignment', 'subject_to_course_resolution' );
    private const UNRESOLVED_COURSE = 'unresolved_intro_course';
    private const ADMIN_ATTRIBUTION = array( 'direct_teacher_statement', 'authorised_staff_witness' );
    private const UNCERTAINTIES = array( 'scope_unconfirmed', 'timing_unconfirmed', 'conditions_unconfirmed' );

    public function __construct( private ?TeacherAvailabilityAssentRepository $repo = null ) { $this->repo ??= new TeacherAvailabilityAssentRepository(); }
    public function recordAdministratorAttestation( array $input ): array { $this->admin(); return $this->record( $input, 'administrator', $this->actor() ); }
    public function recordAuthenticatedTeacher( array $input ): array { $this->teacherCapability(); return $this->record( $input, 'teacher', $this->actor() ); }

    /** Non-authoritative convenience read. Consequential callers must use the locked consumption method. */
    public function current( int $candidateId, string $fingerprint ): ?object {
        $candidateId = Normalizer::id( $candidateId ); $fingerprint = $this->fingerprint( $fingerprint ); $now = gmdate( 'Y-m-d H:i:s' );
        $assent = $this->repo->currentForFingerprint( $candidateId, $fingerprint, $now );
        return $assent && $this->repo->currentSourceReliance( (int) $assent->id ) && $this->repo->currentTeacherReliance( (int) $assent->teacher_id, $assent->course_id === null ? null : (int) $assent->course_id, $now ) ? $assent : null;
    }

    /**
     * Future Proposal issuance must consume the returned authority inside this
     * callback. This increment deliberately creates no Proposal records.
     */
    public function consumeCurrentForFutureProposalIssuance( int $candidateId, string $fingerprint, callable $consume ): mixed {
        $candidateId = Normalizer::id( $candidateId ); $fingerprint = $this->fingerprint( $fingerprint );
        $this->repo->begin(); try {
            $context = $this->recordableContextForUpdate( $candidateId, null );
            $snapshot = $this->repo->snapshotForCandidateFingerprintForUpdate( $candidateId, $fingerprint );
            if ( ! $snapshot || (int) $snapshot->booking_request_id !== (int) $context['request']->id || (int) $snapshot->coordination_case_id !== (int) $context['case']->id || (int) $snapshot->teacher_id !== (int) $context['teacher']->id ) throw new \InvalidArgumentException( 'Frozen arrangement is not compatible with the current Candidate Teacher context' );
            $assent = $this->repo->currentForSnapshotForUpdate( (int) $snapshot->id, $context['now'] );
            if ( ! $assent ) throw new \RuntimeException( 'Current Teacher assent is unavailable' );
            $this->assertCourseEligibilityForUpdate( (int) $context['teacher']->id, $snapshot->course_id === null ? null : (int) $snapshot->course_id, $context['now'] );
            $this->assertRecordedProvenance( $assent, $context['now'] );
            $result = $consume( $assent, $snapshot, $context );
            $this->repo->commit(); return $result;
        } catch ( \Throwable $e ) { $this->repo->rollback(); throw $e; }
    }

    public function withdraw( int $assentId, int $expectedVersion, string $reason ): int { return $this->retire( $assentId, $expectedVersion, $reason, 'withdrawn', false ); }
    public function invalidate( int $assentId, int $expectedVersion, string $reason ): int { return $this->retire( $assentId, $expectedVersion, $reason, 'invalidated', true ); }
    private function retire( int $assentId, int $expectedVersion, string $reason, string $state, bool $adminOnly ): int {
        if ( $adminOnly ) $this->admin(); $assentId = Normalizer::id( $assentId ); $expectedVersion = Normalizer::count( $expectedVersion, 1, PHP_INT_MAX ); $reason = $this->reason( $reason ); $actor = $this->actor();
        $read = $this->repo->assentForRead( $assentId ); if ( ! $read ) throw new \InvalidArgumentException( 'Teacher assent not found' );
        $this->repo->begin(); try {
            $this->retirementContextForUpdate( $read ); $assent = $this->repo->assentForUpdate( $assentId );
            if ( ! $assent || (int) $assent->version !== $expectedVersion || $assent->state !== 'recorded' ) throw new \RuntimeException( 'Teacher assent changed concurrently' );
            if ( ! $adminOnly && ! current_user_can( 'dzn_manage_teacher_availability_assent' ) && ( ! current_user_can( 'dzn_record_own_availability_assent' ) || ! $this->repo->teacherPrincipalHasAuthority( $actor, (int) $assent->teacher_id ) ) ) throw new \RuntimeException( 'Unauthorized' );
            $now = gmdate( 'Y-m-d H:i:s' ); $version = $this->repo->transitionAssent( $assent, $state, $reason, $actor, $now );
            $this->repo->audit( 'teacher_availability_assent', $assentId, 'teacher_availability_assent.' . $state, $actor, $reason, 'state=' . $state . ';version=' . $version, $this->key( $state . ':' . $assentId . ':' . $version ), $now );
            $this->repo->commit(); return $version;
        } catch ( \Throwable $e ) { $this->repo->rollback(); throw $e; }
    }

    private function record( array $input, string $attribution, int $actor ): array {
        $candidateId = Normalizer::id( $input['candidate_id'] ?? null ); $candidateVersion = Normalizer::count( $input['candidate_version'] ?? null, 1, PHP_INT_MAX );
        $replaces = Normalizer::id( $input['supersede_assent_id'] ?? null, false ); $replaceVersion = $replaces ? Normalizer::count( $input['supersede_assent_version'] ?? null, 1, PHP_INT_MAX ) : null;
        if ( ! $this->repo->candidateForRead( $candidateId ) ) throw new \InvalidArgumentException( 'Candidate Teacher not found' );
        $this->repo->begin(); try {
            $context = $this->recordableContextForUpdate( $candidateId, $candidateVersion ); $facts = $this->facts( $input, $context ); $validity = $this->validity( $input );
            if ( (int) $context['teacher']->id !== $facts['teacher_id'] ) throw new \InvalidArgumentException( 'Frozen arrangement Teacher must match Candidate Teacher' );
            if ( $attribution === 'teacher' && ! $this->repo->teacherPrincipalHasAuthority( $actor, $facts['teacher_id'] ) ) throw new \RuntimeException( 'Authenticated Teacher authority is not established' );
            $this->assertCourseEligibilityForUpdate( $facts['teacher_id'], $facts['course_id'], $context['now'] );
            $facts['booking_request_id'] = (int) $context['request']->id; $facts['coordination_case_id'] = (int) $context['case']->id; $facts['candidate_id'] = $candidateId; $facts['created_at'] = $context['now']; $facts['created_by'] = $actor;
            $snapshot = $this->repo->snapshotForCandidateFingerprintForUpdate( $candidateId, $facts['arrangement_fingerprint'] );
            if ( ! $snapshot ) { $facts['uid'] = Identifier::uid(); $snapshotId = $this->repo->createSnapshot( $facts ); $snapshot = $this->repo->snapshotForCandidateFingerprintForUpdate( $candidateId, $facts['arrangement_fingerprint'] ); if ( ! $snapshot || (int) $snapshot->id !== $snapshotId ) throw new \RuntimeException( 'Assent snapshot changed concurrently' ); }
            $existing = $this->repo->currentForSnapshotForUpdate( (int) $snapshot->id, $context['now'] );
            if ( $existing && ( $replaces === null || (int) $existing->id !== $replaces ) ) throw new \InvalidArgumentException( 'A current Teacher assent already exists for this exact arrangement' );
            if ( $replaces !== null ) { $prior = $this->repo->assentForUpdate( $replaces ); if ( ! $prior || (int) $prior->candidate_id !== $candidateId || (int) $prior->version !== $replaceVersion || $prior->state !== 'recorded' ) throw new \RuntimeException( 'Teacher assent changed concurrently' ); }
            $assentUid = Identifier::uid(); $provenance = $this->provenance( $input, $attribution, $actor, $context, $assentUid );
            $id = $this->repo->createAssent( array( 'uid' => $assentUid, 'snapshot_id' => (int) $snapshot->id, 'booking_request_id' => (int) $context['request']->id, 'coordination_case_id' => (int) $context['case']->id, 'candidate_id' => $candidateId, 'teacher_id' => $facts['teacher_id'], 'state' => 'recorded', 'version' => 1, 'valid_until' => $validity['valid_until'], 'review_by' => $validity['review_by'], 'recorded_at' => $context['now'], 'attribution_type' => $attribution, 'teacher_actor_user_id' => $provenance['teacher_actor_user_id'], 'attested_by' => $provenance['attested_by'], 'attribution_basis' => $provenance['attribution_basis'], 'evidence_channel' => $provenance['evidence_channel'], 'evidence_reference' => $provenance['evidence_reference'], 'evidence_at' => $provenance['evidence_at'], 'uncertainty_code' => $provenance['uncertainty_code'], 'withdrawn_at' => null, 'withdrawn_by' => null, 'invalidated_at' => null, 'invalidated_by' => null, 'state_reason_code' => 'recorded', 'superseded_by_assent_id' => null, 'created_at' => $context['now'], 'updated_at' => $context['now'], 'created_by' => $actor, 'updated_by' => $actor ) );
            if ( $replaces !== null ) { $prior = $this->repo->assentForUpdate( $replaces ); $this->repo->transitionAssent( $prior, 'superseded', 'renewed_or_replaced', $actor, $context['now'], $id ); $this->repo->audit( 'teacher_availability_assent', $replaces, 'teacher_availability_assent.superseded', $actor, 'renewed_or_replaced', 'superseded_by=' . $id, $this->key( 'supersede:' . $replaces . ':' . $id ), $context['now'] ); }
            $this->repo->audit( 'teacher_availability_assent', $id, 'teacher_availability_assent.recorded', $actor, 'recorded', 'attribution=' . $attribution . ';snapshot=' . $snapshot->id . ';fingerprint=' . $facts['arrangement_fingerprint'], $this->key( 'record:' . $id ), $context['now'] );
            $this->repo->commit(); return array( 'assent_id' => $id, 'snapshot_id' => (int) $snapshot->id, 'fingerprint' => $facts['arrangement_fingerprint'], 'version' => 1, 'created' => true );
        } catch ( \Throwable $e ) { $this->repo->rollback(); throw $e; }
    }

    /** Locks only ancestry/identity: retirement must work after source ineligibility. */
    private function retirementContextForUpdate( object $assent ): array {
        $candidateRead = $this->repo->candidateForRead( (int) $assent->candidate_id ); if ( ! $candidateRead ) throw new \InvalidArgumentException( 'Candidate Teacher not found' );
        $caseRead = $this->repo->caseForRead( (int) $candidateRead->coordination_case_id ); if ( ! $caseRead ) throw new \InvalidArgumentException( 'Coordination Case not found' );
        $request = $this->repo->requestForUpdate( (int) $caseRead->booking_request_id ); $case = $this->repo->caseForUpdate( (int) $caseRead->id ); $candidate = $this->repo->candidateForUpdate( (int) $candidateRead->id ); $teacher = $this->repo->teacherIdentityForUpdate( (int) $candidateRead->teacher_id );
        if ( ! $request || ! $case || ! $candidate || ! $teacher || (int) $assent->booking_request_id !== (int) $request->id || (int) $assent->coordination_case_id !== (int) $case->id || (int) $assent->candidate_id !== (int) $candidate->id || (int) $assent->teacher_id !== (int) $teacher->id || (int) $case->booking_request_id !== (int) $request->id || (int) $candidate->coordination_case_id !== (int) $case->id || (int) $candidate->teacher_id !== (int) $teacher->id ) throw new \RuntimeException( 'Teacher assent ancestry changed concurrently' );
        return array( 'request' => $request, 'case' => $case, 'candidate' => $candidate, 'teacher' => $teacher );
    }
    /** Locks the complete live source context needed to record or consume authority. */
    private function recordableContextForUpdate( int $candidateId, ?int $expectedCandidateVersion ): array {
        $read = $this->repo->candidateForRead( $candidateId ); if ( ! $read ) throw new \InvalidArgumentException( 'Candidate Teacher not found' );
        $caseRead = $this->repo->caseForRead( (int) $read->coordination_case_id ); if ( ! $caseRead ) throw new \InvalidArgumentException( 'Coordination Case not found' );
        $request = $this->repo->requestForUpdate( (int) $caseRead->booking_request_id ); $case = $this->repo->caseForUpdate( (int) $caseRead->id );
        if ( ! $case || ! $request || (int) $case->booking_request_id !== (int) $request->id || in_array( $case->state, self::CLOSED_CASES, true ) || $request->student_id !== null || $request->lifecycle_status !== 'submitted' || $request->resolution_state !== 'unresolved' || $request->privacy_erased_at !== null ) throw new \InvalidArgumentException( 'Booking Request is not available for Teacher assent' );
        $candidate = $this->repo->candidateForUpdate( $candidateId ); if ( ! $candidate || (int) $candidate->coordination_case_id !== (int) $case->id || ( $expectedCandidateVersion !== null && (int) $candidate->version !== $expectedCandidateVersion ) ) throw new \RuntimeException( 'Candidate Teacher changed concurrently' );
        if ( in_array( $candidate->status, self::CLOSED_CANDIDATES, true ) ) throw new \InvalidArgumentException( 'Candidate Teacher is not available for assent' );
        $teacher = $this->repo->teacherForAssentForUpdate( (int) $candidate->teacher_id ); if ( ! $teacher ) throw new \InvalidArgumentException( 'Candidate Teacher is not currently eligible for assent' );
        return array( 'request' => $request, 'case' => $case, 'candidate' => $candidate, 'teacher' => $teacher, 'now' => gmdate( 'Y-m-d H:i:s' ) );
    }
    private function facts( array $input, array $context ): array {
        $course = Normalizer::id( $input['course_id'] ?? null, false ); $spec = $course === null ? $this->one( $input['unresolved_course_spec'] ?? null, array( self::UNRESOLVED_COURSE ), 'Unresolved Course specification' ) : null;
        if ( $course !== null && (string) ( $input['unresolved_course_spec'] ?? '' ) !== '' ) throw new \InvalidArgumentException( 'Course and unresolved Course specification are mutually exclusive' );
        $facts = array( 'teacher_id' => Normalizer::id( $input['teacher_id'] ?? null ), 'prospective_subject_ref' => 'booking_request:' . (int) $context['request']->id, 'course_id' => $course, 'unresolved_course_spec' => $spec, 'delivery_mode' => $this->one( $input['delivery_mode'] ?? null, array( 'online', 'in_person', 'hybrid' ), 'Delivery mode' ), 'location_scope' => $this->one( $input['location_scope'] ?? null, self::LOCATION_SCOPES, 'Location scope' ), 'frequency_per_week' => Normalizer::count( $input['frequency_per_week'] ?? null, 1, 14 ), 'expected_duration_minutes' => Normalizer::count( $input['expected_duration_minutes'] ?? null, 15, 480 ), 'schedule_constraints' => 'commencement_window_only', 'commencement_window_start' => $this->utc( $input['commencement_window_start'] ?? null, 'Commencement start' ), 'commencement_window_end' => $this->utc( $input['commencement_window_end'] ?? null, 'Commencement end' ), 'timezone' => $this->timezone( $input['timezone'] ?? null ), 'conditions_code' => $this->one( $input['conditions_code'] ?? 'none', self::CONDITIONS, 'Conditions code' ) );
        if ( $facts['commencement_window_end'] <= $facts['commencement_window_start'] ) throw new \InvalidArgumentException( 'Commencement window end must follow start' );
        $facts['arrangement_fingerprint'] = hash( 'sha256', json_encode( $facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ) ); return $facts;
    }
    private function provenance( array $input, string $attribution, int $actor, array $context, string $assentUid ): array {
        if ( $attribution === 'teacher' ) return array( 'teacher_actor_user_id' => $actor, 'attested_by' => null, 'attribution_basis' => 'authenticated_teacher_principal', 'evidence_channel' => 'authenticated_transition', 'evidence_reference' => 'assent:' . $assentUid, 'evidence_at' => $context['now'], 'uncertainty_code' => null );
        $evidenceAt = $this->utc( $input['evidence_at'] ?? null, 'Evidence timestamp' ); if ( $evidenceAt > $context['now'] ) throw new \InvalidArgumentException( 'Evidence timestamp cannot be later than recorded-at time' );
        return array( 'teacher_actor_user_id' => null, 'attested_by' => $actor, 'attribution_basis' => $this->one( $input['attribution_basis'] ?? null, self::ADMIN_ATTRIBUTION, 'Attribution basis' ), 'evidence_channel' => $this->one( $input['evidence_channel'] ?? null, array( 'in_person', 'phone', 'email_reference', 'message_reference', 'other_reference' ), 'Evidence channel' ), 'evidence_reference' => 'case:' . $context['case']->uid . ':assent:' . $assentUid, 'evidence_at' => $evidenceAt, 'uncertainty_code' => $this->optionalOne( $input['uncertainty_code'] ?? null, self::UNCERTAINTIES, 'Uncertainty code' ) );
    }
    private function assertCourseEligibilityForUpdate( int $teacher, ?int $course, string $now ): void { if ( $course !== null && ( ! $this->repo->courseForAssentForUpdate( $course ) || ! $this->repo->teacherEligibleForCourseForUpdate( $teacher, $course, $now ) ) ) throw new \InvalidArgumentException( 'Teacher is not currently eligible for the intended Course' ); }
    private function assertRecordedProvenance( object $assent, string $now ): void {
        if ( $assent->evidence_at > $now ) throw new \InvalidArgumentException( 'Teacher assent provenance is no longer authoritative' );
        if ( $assent->attribution_type === 'teacher' && ( ! $assent->teacher_actor_user_id || $assent->attested_by || $assent->attribution_basis !== 'authenticated_teacher_principal' || $assent->evidence_channel !== 'authenticated_transition' || ! preg_match( '/^assent:[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D', $assent->evidence_reference ) || ! $this->repo->teacherPrincipalHasAuthority( (int) $assent->teacher_actor_user_id, (int) $assent->teacher_id ) ) ) throw new \InvalidArgumentException( 'Teacher assent provenance is no longer authoritative' );
        if ( $assent->attribution_type === 'administrator' && ( ! $assent->attested_by || ! in_array( $assent->attribution_basis, self::ADMIN_ATTRIBUTION, true ) || ! in_array( $assent->evidence_channel, array( 'in_person', 'phone', 'email_reference', 'message_reference', 'other_reference' ), true ) || ( $assent->uncertainty_code !== null && ! in_array( $assent->uncertainty_code, self::UNCERTAINTIES, true ) ) || ! preg_match( '/^case:[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}:assent:[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D', $assent->evidence_reference ) ) ) throw new \InvalidArgumentException( 'Teacher assent provenance is no longer authoritative' );
        if ( ! in_array( $assent->attribution_type, array( 'teacher', 'administrator' ), true ) ) throw new \InvalidArgumentException( 'Teacher assent provenance is no longer authoritative' );
    }
    private function validity( array $input ): array { $valid = $this->utcOptional( $input['valid_until'] ?? null, 'Validity end' ); $review = $this->utcOptional( $input['review_by'] ?? null, 'Review-by' ); $now = gmdate( 'Y-m-d H:i:s' ); if ( $valid === null && $review === null ) throw new \InvalidArgumentException( 'Validity end or review-by timestamp is required' ); if ( ( $valid !== null && $valid <= $now ) || ( $review !== null && $review <= $now ) ) throw new \InvalidArgumentException( 'Validity and review-by must be future timestamps' ); return array( 'valid_until' => $valid, 'review_by' => $review ); }
    private function admin(): void { if ( ! current_user_can( 'dzn_manage_teacher_availability_assent' ) ) throw new \RuntimeException( 'Unauthorized' ); }
    private function teacherCapability(): void { if ( ! current_user_can( 'dzn_record_own_availability_assent' ) ) throw new \RuntimeException( 'Unauthorized' ); }
    private function actor(): int { $actor = get_current_user_id(); if ( $actor < 1 ) throw new \RuntimeException( 'Authenticated actor required' ); return $actor; }
    private function reason( string $value ): string { return $this->one( $value, array( 'teacher_withdrew', 'operational_unavailable', 'eligibility_changed', 'attribution_unreliable', 'material_facts_changed', 'privacy_erased', 'renewed_or_replaced' ), 'Assent reason' ); }
    private function one( mixed $value, array $allowed, string $label ): string { return Normalizer::one( $value, $allowed, $label ); }
    private function optionalOne( mixed $value, array $allowed, string $label ): ?string { return $value === null || $value === '' ? null : $this->one( $value, $allowed, $label ); }
    private function timezone( mixed $value ): string { $timezone = Normalizer::timezone( $value ); if ( ! $timezone ) throw new \InvalidArgumentException( 'IANA timezone required' ); return $timezone; }
    private function utc( mixed $value, string $label ): string { $value = (string) $value; $date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) ); if ( ! $date || $date->format( 'Y-m-d H:i:s' ) !== $value ) throw new \InvalidArgumentException( $label . ' must be canonical UTC datetime' ); return $value; }
    private function utcOptional( mixed $value, string $label ): ?string { return $value === null || $value === '' ? null : $this->utc( $value, $label ); }
    private function fingerprint( string $value ): string { if ( ! preg_match( '/^[a-f0-9]{64}$/D', $value ) ) throw new \InvalidArgumentException( 'Arrangement fingerprint required' ); return $value; }
    private function key( string $value ): string { return hash_hmac( 'sha256', 'teacher-assent:' . $value . ':' . Identifier::uid(), wp_salt( 'dzn_teacher_assent_audit' ) ); }
}
