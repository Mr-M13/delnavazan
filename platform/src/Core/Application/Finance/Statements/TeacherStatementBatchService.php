<?php
namespace Delnavazan\Platform\Core\Application\Finance\Statements;

use Delnavazan\Platform\Core\Application\Finance\{FinanceSupport,TeacherStatementService};
use Delnavazan\Platform\Core\Infrastructure\Repository\FinanceStatementRepository;
use Delnavazan\Platform\Core\Support\PersianCalendar;

/**
 * Generate teacher statements for every Teacher who has active Lesson work in a period.
 *
 * One Teacher's failure never rolls back another Teacher's statement. Each draft/issue call still
 * runs through the recorded statement authority with its own lock, idempotency key and fail-closed
 * issuance gate. The batch is therefore an operator convenience, not a second financial authority.
 *
 * Generating drafts never issues or emails. Issuing and sending are separate operations so an
 * administrator can review a draft first. Persian-month boundaries are Asia/Tehran half-open
 * boundaries; a UTC calendar month is never relabelled as Persian.
 */
final class TeacherStatementBatchService {
    public const VIEW_CAPABILITY = 'dzn_view_finance_authority';
    public const MANAGE_CAPABILITY = 'dzn_manage_finance_statements';

    public function __construct(
        private ?TeacherStatementService $statements = null,
        private ?TeacherStatementEmailService $email = null,
        private ?FinanceStatementRepository $repository = null
    ) {
        $this->statements ??= new TeacherStatementService();
        $this->email ??= new TeacherStatementEmailService();
        $this->repository ??= new FinanceStatementRepository();
    }

    /**
     * Legacy completed UTC month preview, retained for compatible callers.
     *
     * @param list<int> $teacherIds
     * @return array<string,mixed>
     */
    public function preview(string $month, array $teacherIds = array()): array {
        return $this->previewPeriod($this->period($month), $teacherIds);
    }

    /**
     * @param list<int> $teacherIds
     * @return array<string,mixed>
     */
    public function previewPeriod(array $period, array $teacherIds = array()): array {
        FinanceSupport::requireCapability(self::VIEW_CAPABILITY);
        $period = $this->normalisePeriod($period);
        $only = array_values(array_unique(array_filter(array_map('intval', $teacherIds), static fn(int $id): bool => $id > 0)));
        $teachers = array();
        foreach ($this->repository->teachersWithWork($period['period_start'], $period['period_end']) as $row) {
            $teacherId = (int) $row->teacher_id;
            if ($only !== array() && !in_array($teacherId, $only, true)) continue;
            $detail = null;
            $reason = null;
            try {
                $set = $this->statements->periodLessons($teacherId, $period['period_start'], $period['period_end']);
                if (count($set['active']) < 1) continue;
                $detail = $this->statements->previewPeriod($teacherId, $period['period_start'], $period['period_end']);
            } catch (\Throwable $error) {
                $reason = $this->reason($error);
                $set = array('active' => array(), 'archived' => array());
            }
            $live = $this->repository->liveInPeriod($teacherId, $period['period_start'], $period['period_end']);
            $existing = $live === array() ? null : $live[0];
            $teachers[] = array(
                'teacher_id' => $teacherId,
                'teacher_name' => (string) $row->teacher_name,
                'teacher_reference' => (string) $row->teacher_reference,
                'teacher_email' => (string) $row->teacher_email,
                'teacher_locale' => (string) ($row->teacher_locale ?? ''),
                'active_lessons' => (int) ($detail['active_lessons'] ?? count($set['active'])),
                'archived_lessons' => (int) ($detail['archived_lessons'] ?? count($set['archived'])),
                'payable_lessons' => (int) ($detail['payable_lessons'] ?? 0),
                'non_payable_lessons' => (int) ($detail['non_payable_lessons'] ?? 0),
                'pending_lessons' => (int) ($detail['pending_lessons'] ?? 0),
                'missing_count' => is_array($detail['missing'] ?? null) ? count($detail['missing']) : 0,
                'missing' => $detail['missing'] ?? array(),
                'rates' => $detail['rates'] ?? array(),
                'payable_amount_minor' => (int) ($detail['payable_amount_minor'] ?? 0),
                'base_amount_minor' => (int) ($detail['base_amount_minor'] ?? 0),
                'bonus_minor' => (int) ($detail['bonus_minor'] ?? 0),
                'currency' => $detail['currency'] ?? null,
                'lines' => $detail['lines'] ?? array(),
                'existing_statement_id' => $existing === null ? null : (int) $existing->id,
                'existing_state' => $existing === null ? null : (string) $existing->state,
                'existing_period' => $existing === null ? null : array('start' => (string) $existing->period_start_utc, 'end' => (string) $existing->period_end_utc),
                'reason' => $reason,
            );
        }
        return array(
            'month' => $period['month'],
            'period_key' => $period['period_key'],
            'period_label' => $period['period_label'],
            'calendar' => $period['calendar'],
            'timezone' => $period['timezone'],
            'period_start' => $period['period_start'],
            'period_end' => $period['period_end'],
            'teachers' => $teachers,
        );
    }

    /**
     * Legacy completed UTC month generation.
     *
     * @return array<string,mixed>
     */
    public function run(string $month, array $options, string $batchKey): array {
        return $this->runPeriod($this->period($month), $options, $batchKey);
    }

    /**
     * Draft and optionally issue one statement per Teacher with work in the period.
     *
     * `issue` is optional for compatibility; the clarity workflow calls this with issue/email off
     * and then issues only reviewed drafts. Generating never emails unless `email` is explicitly
     * true, which the redesigned UI never combines with draft generation.
     *
     * @param array{issue?:bool|string,email?:bool|string,teacher_ids?:array<int|string>} $options
     * @return array<string,mixed>
     */
    public function runPeriod(array $period, array $options, string $batchKey): array {
        FinanceSupport::requireCapability(self::MANAGE_CAPABILITY);
        $period = $this->normalisePeriod($period);
        $batchKey = trim($batchKey);
        if ($batchKey === '') throw new \InvalidArgumentException('statement_batch_key_required');
        $issue = !empty($options['issue']);
        $sendEmail = $issue && !empty($options['email']);
        $selected = array();
        foreach ((array) ($options['teacher_ids'] ?? array()) as $id) {
            $id = (int) $id;
            if ($id > 0) $selected[] = $id;
        }
        $preview = $this->previewPeriod($period, $selected);
        $results = array();
        $counts = array('teachers' => 0, 'drafted' => 0, 'issued' => 0, 'skipped' => 0, 'failed' => 0, 'email_queued' => 0, 'email_failed' => 0);
        $totals = array();
        foreach ((array) $preview['teachers'] as $candidate) {
            $counts['teachers']++;
            $teacherId = (int) $candidate['teacher_id'];
            $result = array(
                'teacher_id' => $teacherId,
                'teacher_name' => (string) $candidate['teacher_name'],
                'teacher_reference' => (string) $candidate['teacher_reference'],
                'teacher_email' => (string) $candidate['teacher_email'],
                'active_lessons' => (int) $candidate['active_lessons'],
                'payable_lessons' => (int) $candidate['payable_lessons'],
                'missing_count' => (int) $candidate['missing_count'],
                'payable_amount_minor' => (int) $candidate['payable_amount_minor'],
                'bonus_minor' => (int) $candidate['bonus_minor'],
                'currency' => (string) ($candidate['currency'] ?? ''),
                'statement_id' => null,
                'state' => 'skipped',
                'draft_state' => 'not_attempted',
                'issue_state' => $issue ? 'not_attempted' : 'not_requested',
                'email_state' => $sendEmail ? 'not_attempted' : 'not_requested',
                'reason' => null,
                'emailed' => false,
                'email_reason' => null,
            );
            if ((string) ($candidate['reason'] ?? '') !== '') {
                $result['state'] = 'failed';
                $result['draft_state'] = 'failed';
                $result['issue_state'] = 'not_attempted';
                $result['reason'] = (string) $candidate['reason'];
                $counts['failed']++;
                $results[] = $result;
                continue;
            }
            $statementId = null;
            try {
                $existingId = (int) ($candidate['existing_statement_id'] ?? 0);
                $existingState = (string) ($candidate['existing_state'] ?? '');
                $exactExisting = $existingId > 0
                    && (string) ($candidate['existing_period']['start'] ?? '') === (string) $preview['period_start']
                    && (string) ($candidate['existing_period']['end'] ?? '') === (string) $preview['period_end'];

                if ($exactExisting && $existingState === 'issued') {
                    $statementId = $existingId;
                    $result['statement_id'] = $statementId;
                    $result['state'] = 'skipped';
                    $result['reason'] = 'statement_already_issued';
                    $result['draft_state'] = 'existing';
                    $result['issue_state'] = 'already_issued';
                    $counts['skipped']++;
                } elseif ($exactExisting && $existingState === 'draft') {
                    $statementId = $existingId;
                    $result['statement_id'] = $statementId;
                    $result['draft_state'] = 'existing';
                    if ($issue) {
                        $this->statements->issue($statementId, $batchKey . ':issue:' . $teacherId);
                        $result['state'] = 'issued';
                        $result['issue_state'] = 'issued';
                        $counts['issued']++;
                    } else {
                        $result['state'] = 'drafted';
                        $result['issue_state'] = 'not_requested';
                        $counts['drafted']++;
                    }
                } elseif ($existingId > 0) {
                    $result['statement_id'] = $existingId;
                    $result['state'] = 'skipped';
                    $result['reason'] = 'statement_period_overlap';
                    $result['draft_state'] = 'existing';
                    $result['issue_state'] = 'not_attempted';
                    $counts['skipped']++;
                } else {
                    $draft = $this->statements->draft($teacherId, (string) $preview['period_start'], (string) $preview['period_end'], $batchKey . ':draft:' . $teacherId);
                    $statementId = (int) $draft['statement_id'];
                    $result['statement_id'] = $statementId;
                    $result['draft_state'] = 'created';
                    $counts['drafted']++;
                    if ($issue) {
                        $this->statements->issue($statementId, $batchKey . ':issue:' . $teacherId);
                        $result['state'] = 'issued';
                        $result['issue_state'] = 'issued';
                        $counts['issued']++;
                    } else {
                        $result['state'] = 'drafted';
                        $result['issue_state'] = 'not_requested';
                    }
                }

                if ($sendEmail && $result['state'] === 'issued' && $statementId !== null) {
                    $mail = $this->email->enqueue($statementId);
                    $result['emailed'] = (string) ($mail['state'] ?? '') === 'transport_accepted';
                    $result['email_state'] = (string) ($mail['state'] ?? '') === 'transport_accepted' ? 'transport_accepted' : ((string) ($mail['reason'] ?? '') === 'already_transport_accepted' ? 'transport_accepted' : (!empty($mail['queued']) ? 'queued' : 'failed'));
                    $result['email_reason'] = $mail['reason'] ?? null;
                    if ($result['email_state'] === 'failed') $counts['email_failed']++; else $counts['email_queued']++;
                }
            } catch (\Throwable $error) {
                $result['state'] = 'failed';
                $result['reason'] = $this->reason($error);
                if ($result['draft_state'] === 'not_attempted' && $statementId === null) $result['draft_state'] = 'failed';
                if ($issue && $result['issue_state'] === 'not_attempted' && $statementId !== null) $result['issue_state'] = 'failed';
                $counts['failed']++;
            }
            if ($result['state'] !== 'skipped' && $result['state'] !== 'failed' && $result['currency'] !== '') {
                $totals[$result['currency']] ??= array('currency' => $result['currency'], 'amount_minor' => 0);
                $totals[$result['currency']]['amount_minor'] += (int) $result['payable_amount_minor'];
            }
            $results[] = $result;
        }
        ksort($totals);
        return array(
            'month' => $preview['month'],
            'period_key' => $preview['period_key'],
            'period_label' => $preview['period_label'],
            'calendar' => $preview['calendar'],
            'timezone' => $preview['timezone'],
            'period_start' => $preview['period_start'],
            'period_end' => $preview['period_end'],
            'issue' => $issue,
            'email' => $sendEmail,
            'generated_at' => gmdate('Y-m-d H:i:s'),
            'summary' => $counts,
            'totals_by_currency' => array_values($totals),
            'teachers' => $results,
        );
    }

    /** A completed Persian month as a half-open Asia/Tehran range. */
    public function persianPeriod(string $month): array {
        $range = PersianCalendar::monthRange($month, PersianCalendar::DEFAULT_TIMEZONE);
        if ($range['end_utc'] > gmdate('Y-m-d H:i:s')) throw new \InvalidArgumentException('statement_period_not_elapsed');
        return array(
            'month' => $range['key'],
            'period_key' => 'persian:' . $range['key'],
            'period_label' => 'Persian month ' . $range['label_fa'] . ' (' . $range['label'] . ')',
            'calendar' => 'persian',
            'timezone' => $range['timezone'],
            'period_start' => $range['start_utc'],
            'period_end' => $range['end_utc'],
        );
    }

    /** A completed custom inclusive local date range in Asia/Tehran. */
    public function customPeriod(string $startDate, string $endDate): array {
        $range = PersianCalendar::customRange($startDate, $endDate, PersianCalendar::DEFAULT_TIMEZONE);
        if ($range['end_utc'] > gmdate('Y-m-d H:i:s')) throw new \InvalidArgumentException('statement_period_not_elapsed');
        return array(
            'month' => substr($range['start_local'], 0, 7),
            'period_key' => 'custom:' . $startDate . ':' . $endDate,
            'period_label' => 'Custom ' . $range['label'] . ' (' . $range['timezone'] . ')',
            'calendar' => 'custom',
            'timezone' => $range['timezone'],
            'period_start' => $range['start_utc'],
            'period_end' => $range['end_utc'],
        );
    }

    /** @return array{month:string,period_start:string,period_end:string,period_key:string,period_label:string,calendar:string,timezone:string} */
    private function period(string $month): array {
        $month = trim($month);
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month) !== 1) throw new \InvalidArgumentException('statement_batch_month_invalid');
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01', new \DateTimeZone('UTC'));
        if (!$start) throw new \InvalidArgumentException('statement_batch_month_invalid');
        $end = $start->modify('+1 month');
        if ($end->format('Y-m-d H:i:s') > gmdate('Y-m-d H:i:s')) throw new \InvalidArgumentException('statement_period_not_elapsed');
        return array(
            'month' => $month,
            'period_key' => 'utc_month:' . $month,
            'period_label' => 'UTC month ' . $month,
            'calendar' => 'gregorian_utc',
            'timezone' => 'UTC',
            'period_start' => $start->format('Y-m-d H:i:s'),
            'period_end' => $end->format('Y-m-d H:i:s'),
        );
    }

    /** @return array{month:string,period_start:string,period_end:string,period_key:string,period_label:string,calendar:string,timezone:string} */
    private function normalisePeriod(array $period): array {
        foreach (array('period_start', 'period_end') as $key) {
            $value = (string) ($period[$key] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value) !== 1) throw new \InvalidArgumentException('statement_period_invalid');
        }
        if ((string) $period['period_end'] <= (string) $period['period_start']) throw new \InvalidArgumentException('statement_period_invalid');
        if ((string) $period['period_end'] > gmdate('Y-m-d H:i:s')) throw new \InvalidArgumentException('statement_period_not_elapsed');
        return array(
            'month' => (string) ($period['month'] ?? substr((string) $period['period_start'], 0, 7)),
            'period_key' => (string) ($period['period_key'] ?? 'custom'),
            'period_label' => (string) ($period['period_label'] ?? 'Custom period'),
            'calendar' => (string) ($period['calendar'] ?? 'custom'),
            'timezone' => (string) ($period['timezone'] ?? PersianCalendar::DEFAULT_TIMEZONE),
            'period_start' => (string) $period['period_start'],
            'period_end' => (string) $period['period_end'],
        );
    }

    private function reason(\Throwable $error): string {
        $message = trim($error->getMessage());
        return preg_match('/^[a-z0-9_]{3,64}$/D', $message) === 1 ? $message : 'statement_batch_failed';
    }
}
