<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/** Immutable final-acceptance and same-Family option-outcome persistence. */
final class AcceptedServiceArrangementRepository {
    private string $prefix;

    public function __construct() { global $wpdb; $this->prefix = $wpdb->prefix . 'dzn_'; }

    public function arrangementForCommand(string $digest): ?object { global $wpdb; return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->prefix}accepted_service_arrangements WHERE command_key_digest=%s", $digest)); }
    public function arrangementForFamily(int $familyId): ?object { global $wpdb; return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->prefix}accepted_service_arrangements WHERE proposal_family_id=%d", $familyId)); }
    public function arrangementById(int $id): ?object { global $wpdb; return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->prefix}accepted_service_arrangements WHERE id=%d", $id)); }

    public function familyOptionsForUpdate(int $familyId): array {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$this->prefix}proposal_options WHERE proposal_family_id=%d ORDER BY id ASC", $familyId)) ?: array();
        $rows = array(); foreach ($ids as $id) { $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->prefix}proposal_options WHERE id=%d FOR UPDATE", (int) $id)); if ($row) $rows[] = $row; }
        return $rows;
    }
    public function currentVersionsForOptionsForUpdate(int $familyId): array {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare("SELECT v.id FROM {$this->prefix}proposal_options o INNER JOIN {$this->prefix}proposal_versions v ON v.id=o.current_version_id AND v.proposal_option_id=o.id AND v.proposal_family_id=o.proposal_family_id WHERE o.proposal_family_id=%d ORDER BY o.id ASC", $familyId)) ?: array();
        $rows = array(); foreach ($ids as $id) { $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->prefix}proposal_versions WHERE id=%d FOR UPDATE", (int) $id)); if ($row) $rows[] = $row; }
        return $rows;
    }
    public function provisionalForUidForUpdate(string $uid): ?object {
        global $wpdb;
        $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->prefix}proposal_acceptance_events WHERE uid=%s", $uid));
        return $id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->prefix}proposal_acceptance_events WHERE id=%d FOR UPDATE", (int) $id)) : null;
    }

    public function insertArrangement(array $data): int { return $this->insert('accepted_service_arrangements', $data, 'Accepted Service Arrangement persistence failed'); }
    public function assignArrangementReference(int $id, string $reference): void { global $wpdb; if ($wpdb->update($this->prefix . 'accepted_service_arrangements', array('reference_code' => $reference), array('id' => $id, 'reference_code' => null)) !== 1) throw new \RuntimeException('Accepted Service Arrangement reference assignment failed'); }
    public function insertOutcome(array $data): int { return $this->insert('proposal_option_outcome_events', $data, 'Proposal Option outcome persistence failed'); }

    public function audit(int $arrangementId, int $actor, string $facts, string $digest, string $now): void {
        $this->insert('platform_audit_events', array(
            'aggregate_type' => 'accepted_service_arrangement',
            'aggregate_id' => $arrangementId,
            'event_type' => 'accepted_service_arrangement.created',
            'actor_type' => 'user',
            'actor_id' => $actor,
            'reason_code' => 'affirmed_final_acceptance',
            'safe_detail' => $facts,
            'idempotency_key' => $digest,
            'occurred_at' => $now,
        ), 'Final acceptance audit persistence failed');
    }

    public function isDuplicate(\Throwable $e): bool { global $wpdb; return str_contains(strtolower($e->getMessage() . ' ' . $wpdb->last_error), 'duplicate'); }

    private function insert(string $table, array $data, string $message): int {
        global $wpdb;
        if ($wpdb->insert($this->prefix . $table, $data) === false) throw new \RuntimeException($message . ': ' . $wpdb->last_error);
        return (int) $wpdb->insert_id;
    }
}
