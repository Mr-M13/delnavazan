<?php
namespace Delnavazan\Platform\Core\Application\Finance\Statements;

use Delnavazan\Platform\Core\Application\Finance\{FinanceSupport,TeacherStatementService};
use Delnavazan\Platform\Core\Infrastructure\Repository\{FinanceStatementRepository,StatementBatchRunRepository};
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Administrative workflow around the recorded statement authority:
 * Preview -> Generate drafts -> Review -> Issue/publish -> Send.
 *
 * Draft generation never sends email. Issuing still runs the existing fail-closed gate. Sending
 * only enqueues issued statements, and the durable outbox records transport acceptance — never
 * "delivered" without a downstream delivery fact. A user-entered address receives only an
 * administrative summary, never a pile of individual teacher statements.
 */
final class TeacherStatementBatchWorkflowService {
    public const VIEW_CAPABILITY = 'dzn_view_finance_authority';
    public const MANAGE_CAPABILITY = 'dzn_manage_finance_statements';

    public function __construct(
        private ?TeacherStatementBatchService $batch = null,
        private ?TeacherStatementService $statements = null,
        private ?TeacherStatementEmailService $email = null,
        private ?FinanceStatementRepository $repository = null,
        private ?StatementBatchRunRepository $runs = null
    ) {
        $this->batch ??= new TeacherStatementBatchService();
        $this->statements ??= new TeacherStatementService();
        $this->email ??= new TeacherStatementEmailService();
        $this->repository ??= new FinanceStatementRepository();
        $this->runs ??= new StatementBatchRunRepository();
    }

    public function preview(array $period, array $teacherIds = array()): array {
        return $this->batch->previewPeriod($period, $teacherIds);
    }

    /**
     * Generate drafts only. No issue and no email is requested by this method.
     *
     * @return array<string,mixed>
     */
    public function generateDrafts(array $period, array $teacherIds, string $commandKey): array {
        FinanceSupport::requireCapability(self::MANAGE_CAPABILITY);
        $key = $this->key($commandKey);
        $runKey = $this->runKey('manual_drafts', $period, $key);
        $existing = $this->runs->byRunKey($runKey);
        if ($existing && (string) $existing->state === 'completed') {
            $decoded = json_decode((string) $existing->result_json, true);
            if (is_array($decoded)) return $decoded + array('idempotent' => true, 'run_id' => (int) $existing->id);
        }
        $result = $this->batch->runPeriod($period, array('issue' => '', 'email' => '', 'teacher_ids' => $teacherIds), $key . ':drafts');
        $result['run_id'] = $this->record($runKey, $period, 'manual_drafts', $result);
        return $result;
    }

    /**
     * Issue selected drafts. Each passes the canonical issuance gate independently.
     *
     * @param list<int> $statementIds
     * @return array<string,mixed>
     */
    public function issue(array $statementIds, array $period, string $commandKey): array {
        FinanceSupport::requireCapability(self::MANAGE_CAPABILITY);
        $ids = array_values(array_unique(array_filter(array_map('intval', $statementIds), static fn(int $id): bool => $id > 0)));
        if ($ids === array()) throw new \InvalidArgumentException('statement_required');
        $key = $this->key($commandKey);
        $teachers = array();
        $counts = array('teachers' => 0, 'issued' => 0, 'failed' => 0, 'skipped' => 0);
        $totals = array();
        foreach ($ids as $statementId) {
            $counts['teachers']++;
            $row = array('statement_id' => $statementId, 'teacher_id' => 0, 'teacher_name' => '', 'state' => 'failed', 'reason' => null, 'currency' => '', 'amount_minor' => 0);
            try {
                $statement = $this->repository->byId($statementId);
                if (!$statement) throw new \InvalidArgumentException('statement_required');
                $row['teacher_id'] = (int) $statement->teacher_id;
                $row['teacher_name'] = $this->teacherName((int) $statement->teacher_id);
                $row['currency'] = (string) $statement->currency;
                $row['amount_minor'] = (int) $statement->payable_amount_minor;
                if ((string) $statement->state === 'issued') {
                    $row['state'] = 'skipped';
                    $row['reason'] = 'statement_already_issued';
                    $counts['skipped']++;
                } elseif ((string) $statement->state !== 'draft') {
                    $row['state'] = 'skipped';
                    $row['reason'] = 'statement_state_transition_conflict';
                    $counts['skipped']++;
                } else {
                    $this->statements->issue($statementId, $key . ':issue:' . $statementId);
                    $row['state'] = 'issued';
                    $counts['issued']++;
                    $totals[$row['currency']] ??= array('currency' => $row['currency'], 'amount_minor' => 0);
                    $totals[$row['currency']]['amount_minor'] += (int) $row['amount_minor'];
                }
            } catch (\Throwable $error) {
                $row['state'] = 'failed';
                $row['reason'] = $this->reason($error);
                $counts['failed']++;
            }
            $teachers[] = $row;
        }
        $result = array(
            'period_key' => (string) ($period['period_key'] ?? ''),
            'period_label' => (string) ($period['period_label'] ?? ''),
            'calendar' => (string) ($period['calendar'] ?? ''),
            'timezone' => (string) ($period['timezone'] ?? ''),
            'period_start' => (string) ($period['period_start'] ?? ''),
            'period_end' => (string) ($period['period_end'] ?? ''),
            'generated_at' => gmdate('Y-m-d H:i:s'),
            'summary' => $counts,
            'totals_by_currency' => array_values($totals),
            'teachers' => $teachers,
        );
        $result['run_id'] = $this->record($this->runKey('manual_issue', $period, $key), $period, 'manual_issue', $result);
        return $result;
    }

    /**
     * Send issued statements to their own verified Teacher addresses.
     *
     * @param list<int> $statementIds
     * @return array<string,mixed>
     */
    public function send(array $statementIds, array $period, string $commandKey): array {
        FinanceSupport::requireCapability(self::MANAGE_CAPABILITY);
        $ids = array_values(array_unique(array_filter(array_map('intval', $statementIds), static fn(int $id): bool => $id > 0)));
        if ($ids === array()) throw new \InvalidArgumentException('statement_required');
        $key = $this->key($commandKey);
        $teachers = array();
        $counts = array('teachers' => 0, 'queued' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0);
        foreach ($ids as $statementId) {
            $counts['teachers']++;
            $row = array('statement_id' => $statementId, 'teacher_id' => 0, 'teacher_name' => '', 'state' => 'failed', 'reason' => null, 'email_state' => 'not_attempted', 'recipient' => '');
            try {
                $statement = $this->repository->byId($statementId);
                if (!$statement) throw new \InvalidArgumentException('statement_required');
                $row['teacher_id'] = (int) $statement->teacher_id;
                $row['teacher_name'] = $this->teacherName((int) $statement->teacher_id);
                $row['recipient'] = $this->teacherEmail((int) $statement->teacher_id);
                if ((string) $statement->state !== 'issued') {
                    $row['state'] = 'skipped';
                    $row['reason'] = 'statement_not_issued';
                    $counts['skipped']++;
                } else {
                    $mail = $this->email->enqueue($statementId, false);
                    $row['recipient'] = (string) ($mail['recipient'] ?? $row['recipient']);
                    $row['email_state'] = (string) ($mail['state'] ?? 'queued');
                    $row['reason'] = $mail['reason'] ?? null;
                    if (!empty($mail['queued'])) {
                        $row['state'] = 'queued';
                        $counts['queued']++;
                    } else {
                        $row['state'] = 'failed';
                        $counts['failed']++;
                    }
                }
            } catch (\Throwable $error) {
                $row['state'] = 'failed';
                $row['reason'] = $this->reason($error);
                $counts['failed']++;
            }
            $teachers[] = $row;
        }
        // The durable worker owns delivery. This action records a queued intent per selected issued
        // statement; it never processes an unrelated queued statement and never claims delivery.
        $counts['transport_accepted'] = 0;
        $counts['retryable'] = 0;
        $result = array(
            'period_key' => (string) ($period['period_key'] ?? ''),
            'period_label' => (string) ($period['period_label'] ?? ''),
            'calendar' => (string) ($period['calendar'] ?? ''),
            'timezone' => (string) ($period['timezone'] ?? ''),
            'period_start' => (string) ($period['period_start'] ?? ''),
            'period_end' => (string) ($period['period_end'] ?? ''),
            'generated_at' => gmdate('Y-m-d H:i:s'),
            'summary' => $counts,
            'teachers' => $teachers,
        );
        $result['run_id'] = $this->record($this->runKey('manual_send', $period, $key), $period, 'manual_send', $result);
        return $result;
    }

    /**
     * Send an administrative batch summary to one staff address. Individual statements are not
     * attached and are not sent to this address.
     */
    public function sendStaffSummary(array $result, string $email, array $period, string $commandKey): array {
        FinanceSupport::requireCapability(self::MANAGE_CAPABILITY);
        $email = trim($email);
        $valid = function_exists('is_email') ? (bool) is_email($email) : filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        if (!$valid) throw new \InvalidArgumentException('staff_summary_email_invalid');
        $summary = (array) ($result['summary'] ?? array());
        $totals = array_values((array) ($result['totals_by_currency'] ?? array()));
        $failures = array_values(array_filter((array) ($result['teachers'] ?? array()), static fn(array $row): bool => (string) ($row['state'] ?? '') === 'failed'));
        $rows = '';
        foreach ($totals as $total) {
            $rows .= '<tr><td>' . $this->e($total['currency']) . '</td><td>' . $this->e((string) (int) $total['amount_minor']) . ' minor units</td></tr>';
        }
        $failRows = '';
        foreach ($failures as $failure) {
            $failRows .= '<tr><td>' . $this->e((string) ($failure['teacher_name'] ?? '')) . '</td><td>' . $this->e((string) ($failure['reason'] ?? 'failed')) . '</td></tr>';
        }
        $body = '<h2>Teacher statement batch summary</h2>'
            . '<p><strong>Period:</strong> ' . $this->e((string) ($result['period_label'] ?? '')) . ' · ' . $this->e((string) ($result['timezone'] ?? '')) . '</p>'
            . '<p><strong>Teachers:</strong> ' . (int) ($summary['teachers'] ?? 0) . ' · drafted ' . (int) ($summary['drafted'] ?? 0) . ' · issued ' . (int) ($summary['issued'] ?? 0) . ' · skipped ' . (int) ($summary['skipped'] ?? 0) . ' · failed ' . (int) ($summary['failed'] ?? 0) . '</p>'
            . ($rows !== '' ? '<table><thead><tr><th>Currency</th><th>Issued total</th></tr></thead><tbody>' . $rows . '</tbody></table>' : '')
            . ($failRows !== '' ? '<h3>Individual failures</h3><table><thead><tr><th>Teacher</th><th>Reason</th></tr></thead><tbody>' . $failRows . '</tbody></table>' : '')
            . '<p>This is an administrative batch summary. It does not contain or attach individual teacher statements. Teacher statements are only sent to each teacher’s recorded, verified address.</p>';
        $subject = 'Delnavazan teacher statement batch · ' . (string) ($result['period_label'] ?? '');
        $sent = function_exists('wp_mail') && wp_mail($email, $subject, $body, array('Content-Type: text/html; charset=UTF-8'));
        $record = array(
            'period_key' => (string) ($result['period_key'] ?? ''),
            'period_label' => (string) ($result['period_label'] ?? ''),
            'calendar' => (string) ($result['calendar'] ?? ''),
            'timezone' => (string) ($result['timezone'] ?? ''),
            'period_start' => (string) ($result['period_start'] ?? ''),
            'period_end' => (string) ($result['period_end'] ?? ''),
            'generated_at' => gmdate('Y-m-d H:i:s'),
            'summary' => array('teachers' => 0, 'sent' => $sent ? 1 : 0, 'failed' => $sent ? 0 : 1),
            'recipient' => $email,
            'teachers' => array(),
        );
        $record['run_id'] = $this->record($this->runKey('staff_summary', $period, $this->key($commandKey)), $period, 'staff_summary', $record);
        $record['sent'] = $sent;
        return $record;
    }

    /**
     * Collapsible history with period, counts, totals by currency, state and failures.
     *
     * @return list<array<string,mixed>>
     */
    public function history(int $limit = 12): array {
        FinanceSupport::requireCapability(self::VIEW_CAPABILITY);
        $limit = max(1, min(50, $limit));
        $out = array();
        foreach ($this->runs->recent($limit) as $run) {
            $decoded = json_decode((string) $run->result_json, true);
            $decoded = is_array($decoded) ? $decoded : array();
            $teachers = (array) ($decoded['teachers'] ?? array());
            $failures = array_values(array_filter($teachers, static fn(array $row): bool => (string) ($row['state'] ?? '') === 'failed'));
            $out[] = array(
                'id' => (int) $run->id,
                'run_key' => (string) $run->run_key,
                'period_month' => (string) $run->period_month,
                'period_key' => (string) ($decoded['period_key'] ?? ''),
                'period_label' => (string) ($decoded['period_label'] ?? $run->period_month),
                'calendar' => (string) ($decoded['calendar'] ?? ''),
                'mode' => (string) $run->mode,
                'state' => (string) $run->state,
                'attempt_count' => (int) $run->attempt_count,
                'last_error_code' => $run->last_error_code === null ? null : (string) $run->last_error_code,
                'summary' => (array) ($decoded['summary'] ?? array()),
                'totals_by_currency' => array_values((array) ($decoded['totals_by_currency'] ?? array())),
                'failures' => $failures,
                'started_at' => (string) ($run->started_at ?? ''),
                'completed_at' => (string) ($run->completed_at ?? ''),
                'result' => $decoded,
                'retry_params' => array(
                    'period_start' => (string) ($decoded['period_start'] ?? ''),
                    'period_end' => (string) ($decoded['period_end'] ?? ''),
                    'period_key' => (string) ($decoded['period_key'] ?? ''),
                ),
            );
        }
        return $out;
    }

    private function record(string $runKey, array $period, string $mode, array $result): int {
        $existing = $this->runs->byRunKey($runKey);
        if ($existing) {
            if ((string) $existing->state !== 'completed') {
                $this->runs->update((int) $existing->id, (int) $existing->run_version, array(
                    'state' => 'completed',
                    'result_json' => wp_json_encode($result),
                    'completed_at' => gmdate('Y-m-d H:i:s'),
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                    'updated_by' => max(0, (int) get_current_user_id()),
                ));
            }
            return (int) $existing->id;
        }
        $now = gmdate('Y-m-d H:i:s');
        $month = (string) ($period['month'] ?? substr((string) ($period['period_start'] ?? ''), 0, 7));
        if (strlen($month) !== 7) $month = substr((string) ($period['period_start'] ?? $now), 0, 7);
        return $this->runs->insert(array(
            'uid' => Identifier::uid(),
            'run_key' => substr($runKey, 0, 96),
            'period_month' => $month,
            'mode' => $mode,
            'state' => 'completed',
            'attempt_count' => 1,
            'last_error_code' => null,
            'result_json' => wp_json_encode($result),
            'started_at' => $now,
            'completed_at' => $now,
            'lease_expires_at' => null,
            'run_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => max(0, (int) get_current_user_id()),
            'updated_by' => max(0, (int) get_current_user_id()),
        ));
    }

    private function runKey(string $mode, array $period, string $key): string {
        return substr($mode . ':' . (string) ($period['period_key'] ?? 'period') . ':' . hash('sha256', $key), 0, 96);
    }

    private function key(string $raw): string {
        $raw = trim($raw);
        if ($raw === '') throw new \InvalidArgumentException('statement_batch_key_required');
        return $raw;
    }

    private function teacherName(int $teacherId): string {
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        return (string) ($wpdb->get_var($wpdb->prepare("SELECT display_name FROM {$p}teachers WHERE id=%d", $teacherId)) ?: '');
    }

    private function teacherEmail(int $teacherId): string {
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        return (string) ($wpdb->get_var($wpdb->prepare("SELECT email FROM {$p}teachers WHERE id=%d", $teacherId)) ?: '');
    }

    private function reason(\Throwable $error): string {
        $message = trim($error->getMessage());
        return preg_match('/^[a-z0-9_]{3,64}$/D', $message) === 1 ? $message : 'statement_batch_failed';
    }

    private function e(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
