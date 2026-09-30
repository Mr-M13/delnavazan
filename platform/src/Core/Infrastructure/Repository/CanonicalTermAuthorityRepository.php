<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;

/** Enrolment-first transaction boundary dedicated to canonical Term authority. */
final class CanonicalTermAuthorityRepository {
    private string $prefix;
    public function __construct() { global $wpdb; $this->prefix = $wpdb->prefix . 'dzn_'; }
    public function begin(): void { global $wpdb; if ($wpdb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED') === false || $wpdb->query('START TRANSACTION') === false) throw new \RuntimeException('Transaction start failed'); }
    public function commit(): void { global $wpdb; if ($wpdb->query('COMMIT') === false) throw new \RuntimeException('Transaction commit failed'); }
    public function rollback(): void { global $wpdb; $wpdb->query('ROLLBACK'); }
    public function commandForDigest(string $digest): ?object { global $wpdb; return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->prefix}term_commands WHERE command_key_digest=%s", $digest)); }
    public function enrolment(int $id, bool $lock = false): ?object { return $this->row('enrolments', $id, $lock); }
    public function term(int $id, bool $lock = false): ?object { return $this->row('terms', $id, $lock); }
    public function termsForEnrolment(int $enrolmentId, bool $lock = false): array {
        global $wpdb; $suffix = $lock ? ' FOR UPDATE' : '';
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->prefix}terms WHERE enrolment_id=%d ORDER BY sequence_number,id{$suffix}", $enrolmentId)) ?: array();
    }
    public function events(int $termId, bool $lock = false): array {
        global $wpdb; $suffix = $lock ? ' FOR UPDATE' : '';
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->prefix}term_lifecycle_events WHERE term_id=%d ORDER BY event_sequence,id{$suffix}", $termId)) ?: array();
    }
    public function insertTerm(array $data): int { return $this->insert('terms', $data, 'Canonical Term persistence failed'); }
    public function insertEvent(array $data): int { return $this->insert('term_lifecycle_events', $data, 'Canonical Term lifecycle evidence persistence failed'); }
    public function insertCommand(array $data): int { return $this->insert('term_commands', $data, 'Canonical Term command persistence failed'); }
    public function assignReference(int $id, string $reference): void { global $wpdb; if ($wpdb->update($this->prefix.'terms', array('reference_code'=>$reference), array('id'=>$id,'reference_code'=>null)) !== 1) throw new \RuntimeException('Canonical Term reference assignment failed'); }
    public function transition(object $term, string $from, string $to, string $now, int $actor): void {
        global $wpdb; $slot = in_array($to, array('authorised','current'), true) ? 1 : null;
        $changed = $wpdb->update($this->prefix.'terms', array('lifecycle_state'=>$to,'applicable_slot'=>$slot,'updated_at'=>$now,'updated_by'=>$actor), array('id'=>(int)$term->id,'record_model'=>'canonical_enrolment_term_v1','lifecycle_state'=>$from,'applicable_slot'=>1));
        if ($changed === false) throw new \RuntimeException('Canonical Term transition failed: '.$wpdb->last_error);
        if ($changed !== 1) throw new \RuntimeException('Canonical Term changed concurrently');
    }
    /** Only named unique constraints belonging to this aggregate may arbitrate. */
    public function duplicateConstraint(\Throwable $e): ?string {
        if (!preg_match("/Duplicate entry .* for key ['`](?:[^'`.]+\\.)?([^'`]+)['`]/i", $e->getMessage(), $m)) return null;
        $key = strtolower((string)$m[1]);
        return in_array($key, array('command_key_digest','enrolment_sequence','enrolment_applicable','uid','reference_code','term_sequence'), true) ? $key : null;
    }
    private function row(string $table, int $id, bool $lock): ?object { global $wpdb; return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->prefix}{$table} WHERE id=%d".($lock?' FOR UPDATE':''),$id)); }
    private function insert(string $table, array $data, string $message): int { global $wpdb; $old=$wpdb->suppress_errors(true);$ok=$wpdb->insert($this->prefix.$table,$data);$error=$wpdb->last_error;$wpdb->suppress_errors($old);if($ok===false)throw new \RuntimeException($message.': '.$error);return (int)$wpdb->insert_id; }
}
