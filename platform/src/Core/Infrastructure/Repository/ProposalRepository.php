<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/**
 * Proposal persistence. Transactions are owned by the authoritative Assent
 * service so Proposal issuance can extend its lock scope without nesting.
 */
final class ProposalRepository {
    private string $prefix;
    public function __construct() { global $wpdb; $this->prefix = $wpdb->prefix . 'dzn_'; }

    public function familyForRead( int $id ): ?object { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->prefix}proposal_families WHERE id=%d", $id ) ); }
    public function familyForCaseForUpdate( int $caseId ): ?object {
        global $wpdb;
        $id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->prefix}proposal_families WHERE coordination_case_id=%d", $caseId ) );
        return $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->prefix}proposal_families WHERE id=%d FOR UPDATE", (int) $id ) ) : null;
    }
    /** The caller already owns the Proposal Family serialization lock. */
    public function acceptedArrangementForFamily( int $familyId ): ?object { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$this->prefix}accepted_service_arrangements WHERE proposal_family_id=%d", $familyId ) ); }
    public function insertFamily( array $data ): int { return $this->insert( 'proposal_families', $data ); }
    public function assignFamilyReference( int $id, string $reference ): void { $this->assignReference( 'proposal_families', $id, $reference ); }

    /** Deliberately non-locking: replacement uses this only before the Assent lock graph. */
    public function optionForRead( int $id ): ?object { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->prefix}proposal_options WHERE id=%d", $id ) ); }
    public function optionForFamilyCandidateForUpdate( int $familyId, int $candidateId ): ?object {
        global $wpdb;
        $id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->prefix}proposal_options WHERE proposal_family_id=%d AND candidate_id=%d", $familyId, $candidateId ) );
        return $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->prefix}proposal_options WHERE id=%d FOR UPDATE", (int) $id ) ) : null;
    }
    public function optionForFamilyTeacherForUpdate( int $familyId, int $teacherId ): ?object {
        global $wpdb;
        $id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->prefix}proposal_options WHERE proposal_family_id=%d AND teacher_id=%d", $familyId, $teacherId ) );
        return $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->prefix}proposal_options WHERE id=%d FOR UPDATE", (int) $id ) ) : null;
    }
    public function insertOption( array $data ): int { return $this->insert( 'proposal_options', $data ); }
    public function assignOptionReference( int $id, string $reference ): void { $this->assignReference( 'proposal_options', $id, $reference ); }

    public function versionForRead( int $id ): ?object { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->prefix}proposal_versions WHERE id=%d", $id ) ); }
    public function versionForCommand( string $digest ): ?object { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->prefix}proposal_versions WHERE command_key_digest=%s", $digest ) ); }
    public function versionForOptionNumber( int $optionId, int $number ): ?object { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->prefix}proposal_versions WHERE proposal_option_id=%d AND version_number=%d", $optionId, $number ) ); }
    public function versionForOptionFingerprint( int $optionId, string $fingerprint ): ?object { global $wpdb; return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->prefix}proposal_versions WHERE proposal_option_id=%d AND version_fingerprint=%s", $optionId, $fingerprint ) ); }

    public function currentVersionForOption( object $option ): ?object {
        if ( $option->current_version_id === null ) return null;
        global $wpdb;
        $version = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->prefix}proposal_versions WHERE id=%d AND proposal_option_id=%d FOR UPDATE", (int) $option->current_version_id, (int) $option->id ) );
        if ( ! $version ) throw new \RuntimeException( 'Proposal current-version pointer is invalid' );
        return $version;
    }

    public function insertVersion( array $data ): int { return $this->insert( 'proposal_versions', $data ); }
    public function assignVersionReference( int $id, string $reference ): void { $this->assignReference( 'proposal_versions', $id, $reference ); }

    public function advanceOptionCurrent( object $option, ?int $expectedCurrentId, int $nextVersionId, string $now, int $actor ): void {
        global $wpdb;
        $whereCurrent = $expectedCurrentId === null ? 'current_version_id IS NULL' : $wpdb->prepare( 'current_version_id=%d', $expectedCurrentId );
        $sql = $wpdb->prepare(
            "UPDATE {$this->prefix}proposal_options SET current_version_id=%d,version=version+1,updated_at=%s,updated_by=%d WHERE id=%d AND version=%d AND {$whereCurrent}",
            $nextVersionId, $now, $actor, (int) $option->id, (int) $option->version
        );
        if ( $wpdb->query( $sql ) !== 1 ) throw new \RuntimeException( 'Proposal Option changed concurrently' );
    }

    /** Exact Family + Option + Version identity for future acceptance callers. */
    public function exact( string $familyUid, string $optionUid, int $number ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT v.* FROM {$this->prefix}proposal_versions v INNER JOIN {$this->prefix}proposal_options o ON o.id=v.proposal_option_id INNER JOIN {$this->prefix}proposal_families f ON f.id=v.proposal_family_id AND f.id=o.proposal_family_id WHERE f.uid=%s AND o.uid=%s AND v.version_number=%d",
            $familyUid, $optionUid, $number
        ) );
    }

    /** Non-authoritative current pointer; exact() remains the future acceptance primitive. */
    public function current( string $familyUid, string $optionUid ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT v.* FROM {$this->prefix}proposal_families f INNER JOIN {$this->prefix}proposal_options o ON o.proposal_family_id=f.id INNER JOIN {$this->prefix}proposal_versions v ON v.id=o.current_version_id AND v.proposal_option_id=o.id AND v.proposal_family_id=f.id WHERE f.uid=%s AND o.uid=%s",
            $familyUid, $optionUid
        ) );
    }

    public function versionsForCase( int $caseId ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT f.uid family_uid,o.id option_id,o.uid option_uid,o.teacher_id,o.current_version_id,v.id version_id,v.uid version_uid,v.version_number,v.issuance_reason_code,v.issued_at FROM {$this->prefix}proposal_families f INNER JOIN {$this->prefix}proposal_options o ON o.proposal_family_id=f.id INNER JOIN {$this->prefix}proposal_versions v ON v.proposal_option_id=o.id AND v.proposal_family_id=f.id WHERE f.coordination_case_id=%d ORDER BY o.id ASC,v.version_number ASC",
            $caseId
        ) ) ?: array();
    }

    public function audit( int $versionId, string $event, int $actor, string $reason, string $facts, string $digest, string $now ): void {
        $this->insert( 'platform_audit_events', array(
            'aggregate_type' => 'proposal_version',
            'aggregate_id' => $versionId,
            'event_type' => $event,
            'actor_type' => 'user',
            'actor_id' => $actor,
            'reason_code' => $reason,
            'safe_detail' => $facts,
            'idempotency_key' => $digest,
            'occurred_at' => $now,
        ) );
    }

    public function isDuplicate( \Throwable $e ): bool {
        global $wpdb;
        return str_contains( strtolower( $e->getMessage() . ' ' . $wpdb->last_error ), 'duplicate' );
    }

    private function insert( string $table, array $data ): int {
        global $wpdb;
        if ( $wpdb->insert( $this->prefix . $table, $data ) === false ) throw new \RuntimeException( 'Proposal persistence failed: ' . $wpdb->last_error );
        return (int) $wpdb->insert_id;
    }

    private function assignReference( string $table, int $id, string $reference ): void {
        global $wpdb;
        if ( $wpdb->update( $this->prefix . $table, array( 'reference_code' => $reference ), array( 'id' => $id ) ) !== 1 ) {
            throw new \RuntimeException( 'Proposal reference assignment failed' );
        }
    }
}
