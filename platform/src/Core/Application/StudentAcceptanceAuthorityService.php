<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\BookingRequestRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\StudentIdentityAuthorityRepository;
use Delnavazan\Platform\Core\Support\Identifier;

/** Writes only reviewed capacity and authority facts; it never accepts a service arrangement. */
final class StudentAcceptanceAuthorityService {
    public function __construct(
        private ?BookingRequestRepository $transactions = null,
        private ?StudentIdentityAuthorityRepository $records = null
    ) {
        $this->transactions ??= new BookingRequestRepository();
        $this->records ??= new StudentIdentityAuthorityRepository();
    }

    public function classify(
        int $studentId,
        string $classification,
        string $basis,
        string $channel,
        string $evidenceAt,
        int $actor
    ): int {
        if (!in_array($classification, array('adult', 'minor', 'unknown'), true)) {
            throw new \InvalidArgumentException('Invalid capacity classification');
        }
        [$basis, $channel, $evidenceAt] = $this->evidence($basis, $channel, $evidenceAt);
        $this->actor($actor);

        return $this->transaction(function () use ($studentId, $classification, $basis, $channel, $evidenceAt, $actor): int {
            $student = $this->activeStudent($studentId);
            $now = gmdate('Y-m-d H:i:s');
            $prior = $this->records->currentCapacityForUpdate((int) $student->id);
            do_action('dzn_phase_2a2f_capacity_locks_held');
            $id = $this->records->insertCapacity(array(
                'uid' => Identifier::uid(),
                'student_id' => $studentId,
                'classification' => $classification,
                'verification_basis' => $basis,
                'evidence_channel' => $channel,
                'evidence_reference' => 'capacity-classification-' . Identifier::uid(),
                'evidence_at' => $evidenceAt,
                'classified_at' => $now,
                'classified_by' => $actor,
                'supersedes_classification_id' => $prior?->id,
                'created_at' => $now,
                'created_by' => $actor,
            ));
            $this->records->setCapacityProjection($studentId, $id, $actor, $now);
            return $id;
        });
    }

    public function establishPrincipal(
        int $studentId,
        int $userId,
        string $basis,
        string $channel,
        string $evidenceAt,
        int $actor
    ): int {
        [$basis, $channel, $evidenceAt] = $this->evidence($basis, $channel, $evidenceAt);
        $this->actor($actor);

        return $this->transaction(function () use ($studentId, $userId, $basis, $channel, $evidenceAt, $actor): int {
            $now = gmdate('Y-m-d H:i:s');
            $locks = $this->authorityLocks($studentId, array($userId));
            if (
                $this->activePrincipalForStudent($locks['principals'], $studentId)
                || $this->activePrincipalForUser($locks['principals'], $userId)
                || $this->activeGuardianForUser($locks['grants'], $userId, $now)
            ) {
                throw new \InvalidArgumentException('A current Student principal or guardian authority already exists');
            }
            do_action('dzn_phase_2a2f_authority_locks_held');
            $this->records->expireElapsedGrants($locks['grants'], $actor, $now);
            return $this->records->insertPrincipal(
                $this->principalData($studentId, $userId, $basis, $channel, $evidenceAt, $actor, $now)
            );
        });
    }

    public function revokePrincipal(int $linkId, int $version, string $reason, int $actor): void {
        $this->reason($reason);
        $this->actor($actor);
        $discovered = $this->records->principalById($linkId);
        if (!$discovered) {
            throw new \InvalidArgumentException('Student principal link is no longer current');
        }

        $this->transaction(function () use ($linkId, $version, $reason, $actor, $discovered): void {
            $now = gmdate('Y-m-d H:i:s');
            $locks = $this->authorityLocks(
                (int) $discovered->student_id,
                array((int) $discovered->wordpress_user_id)
            );
            $this->currentPrincipal($this->byId($locks['principals'], $linkId), $version);
            do_action('dzn_phase_2a2f_authority_locks_held');
            $this->records->expireElapsedGrants($locks['grants'], $actor, $now);
            $this->records->revokePrincipal($linkId, $version, $actor, $reason, $now);
        });
    }

    public function supersedePrincipal(
        int $linkId,
        int $version,
        int $nextUserId,
        string $basis,
        string $channel,
        string $evidenceAt,
        string $reason,
        int $actor
    ): int {
        $this->reason($reason);
        [$basis, $channel, $evidenceAt] = $this->evidence($basis, $channel, $evidenceAt);
        $this->actor($actor);
        $discovered = $this->records->principalById($linkId);
        if (!$discovered) {
            throw new \InvalidArgumentException('Student principal link is no longer current');
        }

        return $this->transaction(function () use (
            $linkId,
            $version,
            $nextUserId,
            $basis,
            $channel,
            $evidenceAt,
            $reason,
            $actor,
            $discovered
        ): int {
            $studentId = (int) $discovered->student_id;
            $oldUserId = (int) $discovered->wordpress_user_id;
            $now = gmdate('Y-m-d H:i:s');
            $locks = $this->authorityLocks($studentId, array($oldUserId, $nextUserId));
            $this->currentPrincipal($this->byId($locks['principals'], $linkId), $version);
            if (
                $oldUserId === $nextUserId
                || $this->activePrincipalForUser($locks['principals'], $nextUserId)
                || $this->activeGuardianForUser($locks['grants'], $nextUserId, $now)
            ) {
                throw new \InvalidArgumentException('Replacement Student principal is unavailable');
            }
            do_action('dzn_phase_2a2f_authority_locks_held');
            $this->records->expireElapsedGrants($locks['grants'], $actor, $now);
            $this->records->supersedePrincipal($linkId, $version, $actor, $reason, $now);
            $next = $this->records->insertPrincipal(
                $this->principalData($studentId, $nextUserId, $basis, $channel, $evidenceAt, $actor, $now)
            );
            $this->records->setSupersedingPrincipal($linkId, $next);
            return $next;
        });
    }

    public function grantGuardian(
        int $studentId,
        int $userId,
        string $basis,
        string $channel,
        string $evidenceAt,
        ?string $effectiveUntil,
        int $actor
    ): int {
        [$basis, $channel, $evidenceAt] = $this->evidence($basis, $channel, $evidenceAt);
        $this->actor($actor);
        $effectiveFrom = gmdate('Y-m-d H:i:s');
        $effectiveUntil = $this->guardianInterval($effectiveFrom, $effectiveUntil);

        return $this->transaction(function () use (
            $studentId,
            $userId,
            $basis,
            $channel,
            $evidenceAt,
            $effectiveFrom,
            $effectiveUntil,
            $actor
        ): int {
            $locks = $this->authorityLocks($studentId, array($userId));
            if (
                $this->activePrincipalForUser($locks['principals'], $userId)
                || $this->activeGrant($locks['grants'], $studentId, $userId, $effectiveFrom)
            ) {
                throw new \InvalidArgumentException('A current guardian grant or Student principal already exists');
            }
            do_action('dzn_phase_2a2f_authority_locks_held');
            $this->records->expireElapsedGrants($locks['grants'], $actor, $effectiveFrom);
            return $this->records->insertGrant(
                $this->grantData(
                    $studentId,
                    $userId,
                    $basis,
                    $channel,
                    $evidenceAt,
                    $effectiveFrom,
                    $effectiveUntil,
                    $actor
                )
            );
        });
    }

    public function revokeGuardian(int $grantId, int $version, string $reason, int $actor): void {
        $this->reason($reason);
        $this->actor($actor);
        $discovered = $this->records->grantById($grantId);
        if (!$discovered) {
            throw new \InvalidArgumentException('Acceptance authority grant is no longer current');
        }

        $this->transaction(function () use ($grantId, $version, $reason, $actor, $discovered): void {
            $now = gmdate('Y-m-d H:i:s');
            $locks = $this->authorityLocks(
                (int) $discovered->student_id,
                array((int) $discovered->acting_wordpress_user_id)
            );
            $current = $this->byId($locks['grants'], $grantId);
            $this->currentGrant($current, $version, $now);
            do_action('dzn_phase_2a2f_authority_locks_held');
            $this->records->expireElapsedGrants($locks['grants'], $actor, $now);
            $this->records->revokeGrant($grantId, $version, $actor, $reason, $now);
        });
    }

    public function supersedeGuardian(
        int $grantId,
        int $version,
        int $nextUserId,
        string $basis,
        string $channel,
        string $evidenceAt,
        ?string $effectiveUntil,
        string $reason,
        int $actor
    ): int {
        $this->reason($reason);
        [$basis, $channel, $evidenceAt] = $this->evidence($basis, $channel, $evidenceAt);
        $this->actor($actor);
        $now = gmdate('Y-m-d H:i:s');
        $effectiveUntil = $this->guardianInterval($now, $effectiveUntil);
        $discovered = $this->records->grantById($grantId);
        if (!$discovered) {
            throw new \InvalidArgumentException('Acceptance authority grant is no longer current');
        }

        return $this->transaction(function () use (
            $grantId,
            $version,
            $nextUserId,
            $basis,
            $channel,
            $evidenceAt,
            $effectiveUntil,
            $reason,
            $actor,
            $discovered
        ): int {
            $studentId = (int) $discovered->student_id;
            $oldUserId = (int) $discovered->acting_wordpress_user_id;
            $now = gmdate('Y-m-d H:i:s');
            $locks = $this->authorityLocks($studentId, array($oldUserId, $nextUserId));
            $this->currentGrant($this->byId($locks['grants'], $grantId), $version, $now);
            if (
                $oldUserId === $nextUserId
                || $this->activePrincipalForUser($locks['principals'], $nextUserId)
                || $this->activeGrant($locks['grants'], $studentId, $nextUserId, $now)
            ) {
                throw new \InvalidArgumentException('Replacement guardian authority is unavailable');
            }
            do_action('dzn_phase_2a2f_authority_locks_held');
            $this->records->expireElapsedGrants($locks['grants'], $actor, $now);
            $this->records->markGrantSuperseded($grantId, $version, $actor, $reason, $now);
            $next = $this->records->insertGrant(
                $this->grantData(
                    $studentId,
                    $nextUserId,
                    $basis,
                    $channel,
                    $evidenceAt,
                    $now,
                    $effectiveUntil,
                    $actor
                )
            );
            $this->records->setSupersedingGrant($grantId, $next);
            return $next;
        });
    }

    /** Canonical authority lock order: Student, WP users by ID, principal links by ID, guardian grants by ID. */
    private function authorityLocks(int $studentId, array $userIds): array {
        $student = $this->activeStudent($studentId);
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        sort($userIds, SORT_NUMERIC);
        $users = $this->records->lockWordpressUsers($userIds);
        if (count($users) !== count($userIds)) {
            throw new \InvalidArgumentException('WordPress user does not exist');
        }
        return array(
            'student' => $student,
            'users' => $users,
            'principals' => $this->records->lockPrincipalsForAuthority($studentId, $userIds),
            'grants' => $this->records->lockGrantsForAuthority($studentId, $userIds),
        );
    }

    private function activeStudent(int $studentId): object {
        $student = $this->records->studentForUpdate($studentId);
        if (!$student || $student->status !== 'active' || $student->archived_at !== null) {
            throw new \InvalidArgumentException('Student is not active');
        }
        return $student;
    }

    private function activePrincipalForStudent(array $rows, int $studentId): ?object {
        foreach ($rows as $row) {
            if ((int) $row->student_id === $studentId && $row->status === 'active' && (int) $row->active_slot === 1) {
                return $row;
            }
        }
        return null;
    }

    private function activePrincipalForUser(array $rows, int $userId): ?object {
        foreach ($rows as $row) {
            if ((int) $row->wordpress_user_id === $userId && $row->status === 'active' && (int) $row->active_slot === 1) {
                return $row;
            }
        }
        return null;
    }

    private function activeGuardianForUser(array $rows, int $userId, string $now): ?object {
        foreach ($rows as $row) {
            if (
                (int) $row->acting_wordpress_user_id === $userId
                && $row->authority_type === 'guardian_representative'
                && $row->authority_scope === 'service_acceptance'
                && $row->state === 'active'
                && (int) $row->active_slot === 1
                && !$this->records->isElapsedGrant($row, $now)
            ) {
                return $row;
            }
        }
        return null;
    }

    private function activeGrant(array $rows, int $studentId, int $userId, string $now): ?object {
        foreach ($rows as $row) {
            if (
                (int) $row->student_id === $studentId
                && (int) $row->acting_wordpress_user_id === $userId
                && $row->authority_scope === 'service_acceptance'
                && $row->state === 'active'
                && (int) $row->active_slot === 1
                && !$this->records->isElapsedGrant($row, $now)
            ) {
                return $row;
            }
        }
        return null;
    }

    private function byId(array $rows, int $id): ?object {
        foreach ($rows as $row) {
            if ((int) $row->id === $id) {
                return $row;
            }
        }
        return null;
    }

    private function currentPrincipal(?object $row, int $version): void {
        if (!$row || $row->status !== 'active' || (int) $row->active_slot !== 1 || (int) $row->version !== $version) {
            throw new \InvalidArgumentException('Student principal link is no longer current');
        }
    }

    private function currentGrant(?object $row, int $version, string $now): void {
        if (
            !$row
            || $row->state !== 'active'
            || (int) $row->active_slot !== 1
            || $row->authority_type !== 'guardian_representative'
            || $row->authority_scope !== 'service_acceptance'
            || (int) $row->version !== $version
            || $this->records->isElapsedGrant($row, $now)
        ) {
            throw new \InvalidArgumentException('Acceptance authority grant is no longer current');
        }
    }

    private function principalData(
        int $studentId,
        int $userId,
        string $basis,
        string $channel,
        string $evidenceAt,
        int $actor,
        string $now
    ): array {
        return array(
            'student_id' => $studentId,
            'wordpress_user_id' => $userId,
            'status' => 'active',
            'link_sequence' => $this->records->nextPrincipalSequence($studentId),
            'active_slot' => 1,
            'version' => 1,
            'verification_basis' => $basis,
            'evidence_channel' => $channel,
            'evidence_reference' => 'student-principal-link-' . Identifier::uid(),
            'evidence_at' => $evidenceAt,
            'linked_at' => $now,
            'linked_by' => $actor,
            'updated_at' => $now,
            'updated_by' => $actor,
            'revoked_at' => null,
            'revoked_by' => null,
            'superseded_at' => null,
            'superseded_by' => null,
            'superseded_by_link_id' => null,
            'reason_code' => null,
        );
    }

    private function grantData(
        int $studentId,
        int $userId,
        string $basis,
        string $channel,
        string $evidenceAt,
        string $effectiveFrom,
        ?string $effectiveUntil,
        int $actor
    ): array {
        return array(
            'uid' => Identifier::uid(),
            'reference_code' => null,
            'student_id' => $studentId,
            'acting_wordpress_user_id' => $userId,
            'authority_type' => 'guardian_representative',
            'authority_scope' => 'service_acceptance',
            'state' => 'active',
            'active_slot' => 1,
            'effective_from' => $effectiveFrom,
            'effective_until' => $effectiveUntil,
            'verification_basis' => $basis,
            'evidence_channel' => $channel,
            'evidence_reference' => 'guardian-authority-grant-' . Identifier::uid(),
            'evidence_at' => $evidenceAt,
            'version' => 1,
            'granted_at' => $effectiveFrom,
            'granted_by' => $actor,
            'revoked_at' => null,
            'revoked_by' => null,
            'superseded_at' => null,
            'superseded_by_grant_id' => null,
            'reason_code' => null,
            'created_at' => $effectiveFrom,
            'updated_at' => $effectiveFrom,
            'created_by' => $actor,
            'updated_by' => $actor,
        );
    }

    private function evidence(string $basis, string $channel, string $at): array {
        if (
            !in_array($basis, array('human_review', 'verified_internal_record', 'legal_representative_review', 'synthetic_fixture'), true)
            || !in_array($channel, array('internal_case', 'reviewer_attestation', 'verified_internal_record', 'synthetic_fixture'), true)
        ) {
            throw new \InvalidArgumentException('Invalid reviewed evidence metadata');
        }
        return array($basis, $channel, $this->utc($at, 'reviewed evidence date'));
    }

    private function guardianInterval(string $effectiveFrom, ?string $effectiveUntil): ?string {
        $effectiveFrom = $this->utc($effectiveFrom, 'authority start date');
        if ($effectiveUntil === null) {
            return null;
        }
        $effectiveUntil = $this->utc($effectiveUntil, 'authority end date');
        if ($effectiveUntil <= $effectiveFrom) {
            throw new \InvalidArgumentException('Authority end date must be later than its start date');
        }
        return $effectiveUntil;
    }

    private function utc(string $value, string $label): string {
        $zone = new \DateTimeZone('UTC');
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $zone);
        $errors = \DateTimeImmutable::getLastErrors();
        if (
            !$parsed
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format('Y-m-d H:i:s') !== $value
        ) {
            throw new \InvalidArgumentException('Invalid ' . $label);
        }
        return $value;
    }

    private function reason(string $reason): void {
        if (!preg_match('/^[a-z0-9_]{3,64}$/D', $reason)) {
            throw new \InvalidArgumentException('Invalid reason code');
        }
    }

    private function actor(int $actor): void {
        if ($actor < 1) {
            throw new \InvalidArgumentException('Authority actor is unavailable');
        }
    }

    private function transaction(callable $operation): mixed {
        $this->transactions->begin();
        try {
            $result = $operation();
            $this->transactions->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->transactions->rollback();
            throw $e;
        }
    }
}
