<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/** Persistence for immutable pre-issue arrangement facts and their assent lifecycle. */
final class TeacherAvailabilityAssentRepository {
    private string $prefix;
    public function __construct() { global $wpdb; $this->prefix = $wpdb->prefix . 'dzn_'; }
    public function begin(): void { global $wpdb; if ( $wpdb->query( 'START TRANSACTION' ) === false ) throw new \RuntimeException( 'Transaction start failed' ); }
    public function commit(): void { global $wpdb; if ( $wpdb->query( 'COMMIT' ) === false ) throw new \RuntimeException( 'Transaction commit failed' ); }
    public function rollback(): void { global $wpdb; $wpdb->query( 'ROLLBACK' ); }

    public function candidateForRead( int $id ): ?object { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->prefix}coordination_case_candidates WHERE id=%d", $id ) ); }
    public function requestForUpdate( int $id ): ?object { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->prefix}booking_requests WHERE id=%d FOR UPDATE", $id ) ); }
    public function caseForRead( int $id ): ?object { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->prefix}coordination_cases WHERE id=%d", $id ) ); }
    public function caseForUpdate( int $id ): ?object { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->prefix}coordination_cases WHERE id=%d FOR UPDATE", $id ) ); }
    public function candidateForUpdate( int $id ): ?object { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->prefix}coordination_case_candidates WHERE id=%d FOR UPDATE", $id ) ); }
    /** Identity lock for retirement; deliberately does not require current eligibility. */
    public function teacherIdentityForUpdate( int $id ): ?object { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->prefix}teachers WHERE id=%d FOR UPDATE", $id ) ); }
    public function teacherForAssentForUpdate( int $id ): ?object { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT t.* FROM {$this->prefix}teachers t INNER JOIN {$this->prefix}teacher_onboarding_states o ON o.teacher_id=t.id INNER JOIN {$this->prefix}teacher_accepting_states a ON a.teacher_id=t.id WHERE t.id=%d AND t.status='active' AND t.archived_at IS NULL AND o.state='active' AND o.readiness_state='ready' AND a.state IN ('accepting','limited') FOR UPDATE", $id ) ); }
    public function teacherCurrent( int $id ): ?object { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT t.* FROM {$this->prefix}teachers t INNER JOIN {$this->prefix}teacher_onboarding_states o ON o.teacher_id=t.id INNER JOIN {$this->prefix}teacher_accepting_states a ON a.teacher_id=t.id WHERE t.id=%d AND t.status='active' AND t.archived_at IS NULL AND o.state='active' AND o.readiness_state='ready' AND a.state IN ('accepting','limited')", $id ) ); }
    public function courseForRead( int $id ): ?object { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->prefix}courses WHERE id=%d AND status='active' AND archived_at IS NULL", $id ) ); }
    public function courseForAssentForUpdate( int $id ): ?object { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->prefix}courses WHERE id=%d AND status='active' AND archived_at IS NULL FOR UPDATE", $id ) ); }
    public function teacherEligibleForCourse( int $teacher, int $course, string $now ): bool { global $wpdb; return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT e.id FROM {$this->prefix}teacher_course_eligibilities e WHERE e.teacher_id=%d AND e.course_id=%d AND e.status='active' AND (e.effective_from IS NULL OR e.effective_from<=%s) AND (e.effective_until IS NULL OR e.effective_until>%s) LIMIT 1", $teacher, $course, $now, $now ) ); }
    public function teacherEligibleForCourseForUpdate( int $teacher, int $course, string $now ): bool { global $wpdb; return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT e.id FROM {$this->prefix}teacher_course_eligibilities e WHERE e.teacher_id=%d AND e.course_id=%d AND e.status='active' AND (e.effective_from IS NULL OR e.effective_from<=%s) AND (e.effective_until IS NULL OR e.effective_until>%s) LIMIT 1 FOR UPDATE", $teacher, $course, $now, $now ) ); }
    public function teacherPrincipalHasAuthority( int $user, int $teacher ): bool { global $wpdb; return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT l.id FROM {$this->prefix}teacher_principal_links l INNER JOIN {$this->prefix}teacher_onboarding_states o ON o.teacher_id=l.teacher_id INNER JOIN {$this->prefix}teachers t ON t.id=l.teacher_id WHERE l.wordpress_user_id=%d AND l.teacher_id=%d AND l.status='active' AND o.state='active' AND o.readiness_state='ready' AND t.status='active' AND t.archived_at IS NULL LIMIT 1", $user, $teacher ) ); }

    public function snapshotForCandidateFingerprintForUpdate( int $candidate, string $fingerprint ): ?object { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->prefix}teacher_availability_assent_snapshots WHERE candidate_id=%d AND arrangement_fingerprint=%s FOR UPDATE", $candidate, $fingerprint ) ); }
    public function createSnapshot( array $data ): int { global $wpdb; if ( $wpdb->insert( $this->prefix . 'teacher_availability_assent_snapshots', $data ) === false ) throw new \RuntimeException( 'Assent snapshot persistence failed' ); return (int) $wpdb->insert_id; }
    public function assentForRead( int $id ): ?object { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->prefix}teacher_availability_assents WHERE id=%d", $id ) ); }
    public function assentForUpdate( int $id ): ?object { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->prefix}teacher_availability_assents WHERE id=%d FOR UPDATE", $id ) ); }
    public function currentForSnapshotForUpdate( int $snapshot, string $now ): ?object { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->prefix}teacher_availability_assents WHERE snapshot_id=%d AND state='recorded' AND (valid_until IS NULL OR valid_until>%s) AND (review_by IS NULL OR review_by>%s) ORDER BY id DESC LIMIT 1 FOR UPDATE", $snapshot, $now, $now ) ); }
    public function createAssent( array $data ): int { global $wpdb; if ( $wpdb->insert( $this->prefix . 'teacher_availability_assents', $data ) === false ) throw new \RuntimeException( 'Teacher assent persistence failed' ); return (int) $wpdb->insert_id; }
    public function transitionAssent( object $assent, string $state, string $reason, int $actor, string $now, ?int $supersededBy = null ): int {
        global $wpdb;
        $data = array( 'state' => $state, 'state_reason_code' => $reason, 'version' => (int) $assent->version + 1, 'updated_at' => $now, 'updated_by' => $actor );
        if ( $state === 'withdrawn' ) { $data['withdrawn_at'] = $now; $data['withdrawn_by'] = $actor; }
        if ( $state === 'invalidated' ) { $data['invalidated_at'] = $now; $data['invalidated_by'] = $actor; }
        if ( $state === 'superseded' ) $data['superseded_by_assent_id'] = $supersededBy;
        $changed = $wpdb->update( $this->prefix . 'teacher_availability_assents', $data, array( 'id' => $assent->id, 'version' => $assent->version, 'state' => 'recorded' ) );
        if ( $changed === false ) throw new \RuntimeException( 'Teacher assent persistence failed' );
        if ( $changed !== 1 ) throw new \RuntimeException( 'Teacher assent changed concurrently' );
        return (int) $data['version'];
    }
    public function currentForFingerprint( int $candidate, string $fingerprint, string $now ): ?object { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT a.*,s.course_id,s.arrangement_fingerprint FROM {$this->prefix}teacher_availability_assents a INNER JOIN {$this->prefix}teacher_availability_assent_snapshots s ON s.id=a.snapshot_id WHERE a.candidate_id=%d AND s.arrangement_fingerprint=%s AND a.state='recorded' AND (a.valid_until IS NULL OR a.valid_until>%s) AND (a.review_by IS NULL OR a.review_by>%s) ORDER BY a.id DESC LIMIT 1", $candidate, $fingerprint, $now, $now ) ); }
    public function currentSourceReliance( int $assent ): bool { global $wpdb; return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT a.id FROM {$this->prefix}teacher_availability_assents a INNER JOIN {$this->prefix}booking_requests r ON r.id=a.booking_request_id INNER JOIN {$this->prefix}coordination_cases c ON c.id=a.coordination_case_id AND c.booking_request_id=r.id INNER JOIN {$this->prefix}coordination_case_candidates x ON x.id=a.candidate_id AND x.coordination_case_id=c.id AND x.teacher_id=a.teacher_id WHERE a.id=%d AND r.student_id IS NULL AND r.lifecycle_status='submitted' AND r.resolution_state='unresolved' AND r.privacy_erased_at IS NULL AND c.state NOT IN ('withdrawn','declined','unable_to_arrange','abandoned') AND x.status NOT IN ('not_available','not_suitable','withdrawn_from_consideration','superseded','closed')", $assent ) ); }
    public function currentTeacherReliance( int $teacher, ?int $course, string $now ): bool {
        if ( ! $this->teacherCurrent( $teacher ) ) return false;
        return $course === null || ( $this->courseForRead( $course ) && $this->teacherEligibleForCourse( $teacher, $course, $now ) );
    }

    /** Called inside the Booking Request privacy-erasure transaction after the Case is locked. */
    public function invalidateForPrivacyErasure( int $request, int $actor, string $now, callable $key ): void {
        global $wpdb;
        // The caller has already locked the Booking Request, Case, and Candidates.
        // Complete the documented order by locking Teachers before Assent rows.
        $teachers = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT teacher_id FROM {$this->prefix}coordination_case_candidates x INNER JOIN {$this->prefix}coordination_cases c ON c.id=x.coordination_case_id WHERE c.booking_request_id=%d ORDER BY teacher_id FOR UPDATE", $request ) );
        foreach ( $teachers as $teacher ) $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$this->prefix}teachers WHERE id=%d FOR UPDATE", (int) $teacher ) );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->prefix}teacher_availability_assents WHERE booking_request_id=%d AND state='recorded' ORDER BY candidate_id,teacher_id,id FOR UPDATE", $request ) );
        foreach ( $rows as $assent ) {
            $version = $this->transitionAssent( $assent, 'invalidated', 'privacy_erased', $actor, $now );
            $this->audit( 'teacher_availability_assent', (int) $assent->id, 'teacher_availability_assent.invalidated_privacy_erasure', $actor, 'privacy_erased', 'state=invalidated;version=' . $version, $key( 'assent:' . $assent->id ), $now );
        }
    }
    /** Called within an already-locked Coordination Case transition transaction. */
    public function invalidateRecordedForCaseRetirement( int $case, int $actor, string $now, callable $key ): void {
        global $wpdb;
        $teachers = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT teacher_id FROM {$this->prefix}coordination_case_candidates WHERE coordination_case_id=%d ORDER BY teacher_id FOR UPDATE", $case ) );
        foreach ( $teachers as $teacher ) $this->teacherIdentityForUpdate( (int) $teacher );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->prefix}teacher_availability_assents WHERE coordination_case_id=%d AND state='recorded' ORDER BY candidate_id,teacher_id,id FOR UPDATE", $case ) );
        foreach ( $rows as $assent ) { $version = $this->transitionAssent( $assent, 'invalidated', 'material_facts_changed', $actor, $now ); $this->audit( 'teacher_availability_assent', (int) $assent->id, 'teacher_availability_assent.invalidated_upstream', $actor, 'material_facts_changed', 'state=invalidated;version=' . $version, $key( 'case-retirement:' . $assent->id ), $now ); }
    }
    /** Called within an already-locked Candidate transition transaction. */
    public function invalidateRecordedForCandidateRetirement( int $candidate, int $actor, string $now, callable $key ): void {
        global $wpdb;
        $row = $this->candidateForUpdate( $candidate ); if ( ! $row ) return; $this->teacherIdentityForUpdate( (int) $row->teacher_id );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->prefix}teacher_availability_assents WHERE candidate_id=%d AND state='recorded' ORDER BY id FOR UPDATE", $candidate ) );
        foreach ( $rows as $assent ) { $version = $this->transitionAssent( $assent, 'invalidated', 'eligibility_changed', $actor, $now ); $this->audit( 'teacher_availability_assent', (int) $assent->id, 'teacher_availability_assent.invalidated_upstream', $actor, 'eligibility_changed', 'state=invalidated;version=' . $version, $key( 'candidate-retirement:' . $assent->id ), $now ); }
    }
    public function audit( string $aggregate, int $id, string $event, int $actor, string $reason, string $detail, string $key, string $now ): void { global $wpdb; if ( $wpdb->insert( $this->prefix . 'platform_audit_events', array( 'aggregate_type' => $aggregate, 'aggregate_id' => $id, 'event_type' => $event, 'actor_type' => 'user', 'actor_id' => $actor, 'reason_code' => $reason, 'safe_detail' => $detail, 'idempotency_key' => $key, 'occurred_at' => $now ) ) === false ) throw new \RuntimeException( 'Teacher assent audit persistence failed' ); }
    public function assents( int $case ): array { global $wpdb; return $wpdb->get_results( $wpdb->prepare( "SELECT a.*,s.arrangement_fingerprint,s.delivery_mode,s.timezone FROM {$this->prefix}teacher_availability_assents a INNER JOIN {$this->prefix}teacher_availability_assent_snapshots s ON s.id=a.snapshot_id WHERE a.coordination_case_id=%d ORDER BY a.id DESC", $case ) ); }
}
