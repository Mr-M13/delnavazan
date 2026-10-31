<?php
namespace Delnavazan\Platform\Core\Application\Finance\Statements;

use Delnavazan\Platform\Core\Application\Finance\{FinanceSupport,TeacherStatementService};
use Delnavazan\Platform\Core\Infrastructure\Repository\FinanceStatementRepository;

/**
 * Generate monthly teacher statements for every Teacher who has active Lesson work in the period.
 *
 * One Teacher's failure never rolls back another Teacher's statement. Each draft/issue call still
 * runs through the recorded statement authority with its own lock, idempotency key and fail-closed
 * issuance gate. The batch is therefore an operator convenience, not a second financial authority.
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
     * The monthly candidate list. Only Teachers with at least one active (non-archived) Lesson in the
     * period are returned. An existing live statement is reported rather than silently replaced.
     *
     * @param list<int> $teacherIds empty means every Teacher with work
     * @return array{month:string,period_start:string,period_end:string,teachers:list<array<string,mixed>>}
     */
    public function preview(string $month, array $teacherIds = array()): array {
        FinanceSupport::requireCapability(self::VIEW_CAPABILITY);
        $period = $this->period($month);
        $only = array_values(array_unique(array_filter(array_map('intval', $teacherIds), static fn(int $id): bool => $id > 0)));
        $teachers = array();
        foreach ($this->repository->teachersWithWork($period['period_start'], $period['period_end']) as $row) {
            $teacherId = (int) $row->teacher_id;
            if ($only !== array() && !in_array($teacherId, $only, true)) continue;
            try {
                $set = $this->statements->periodLessons($teacherId, $period['period_start'], $period['period_end']);
            } catch (\Throwable $error) {
                $teachers[] = array(
                    'teacher_id' => $teacherId,
                    'teacher_name' => (string) $row->teacher_name,
                    'teacher_reference' => (string) $row->teacher_reference,
                    'teacher_email' => (string) $row->teacher_email,
                    'active_lessons' => 0,
                    'archived_lessons' => 0,
                    'existing_statement_id' => null,
                    'existing_state' => null,
                    'existing_period' => null,
                    'reason' => $this->reason($error),
                );
                continue;
            }
            $active = count($set['active']);
            if ($active < 1) continue;
            $live = $this->repository->liveInPeriod($teacherId, $period['period_start'], $period['period_end']);
            $existing = $live === array() ? null : $live[0];
            $teachers[] = array(
                'teacher_id' => $teacherId,
                'teacher_name' => (string) $row->teacher_name,
                'teacher_reference' => (string) $row->teacher_reference,
                'teacher_email' => (string) $row->teacher_email,
                'active_lessons' => $active,
                'archived_lessons' => count($set['archived']),
                'existing_statement_id' => $existing === null ? null : (int) $existing->id,
                'existing_state' => $existing === null ? null : (string) $existing->state,
                'existing_period' => $existing === null ? null : array('start' => (string) $existing->period_start_utc, 'end' => (string) $existing->period_end_utc),
                'reason' => null,
            );
        }
        return array('month' => $period['month'], 'period_start' => $period['period_start'], 'period_end' => $period['period_end'], 'teachers' => $teachers);
    }

    /**
     * Draft and optionally issue/email one statement per Teacher with work in the month.
     *
     * @param array{issue?:bool|string,email?:bool|string,teacher_ids?:array<int|string>} $options
     * @return array<string,mixed>
     */
    public function run(string $month, array $options, string $batchKey): array {
        FinanceSupport::requireCapability(self::MANAGE_CAPABILITY);
        $batchKey = trim($batchKey);
        if ($batchKey === '') throw new \InvalidArgumentException('statement_batch_key_required');
        $issue = !empty($options['issue']);
        $sendEmail = $issue && !empty($options['email']);
        $selected = array();
        foreach ((array) ($options['teacher_ids'] ?? array()) as $id) {
            $id = (int) $id;
            if ($id > 0) $selected[] = $id;
        }
        $preview = $this->preview($month, $selected);
        $results = array();
        $counts = array('teachers' => 0, 'drafted' => 0, 'issued' => 0, 'skipped' => 0, 'failed' => 0, 'emailed' => 0);
        foreach ((array) $preview['teachers'] as $candidate) {
            $counts['teachers']++;
            $teacherId = (int) $candidate['teacher_id'];
            $result = array(
                'teacher_id' => $teacherId,
                'teacher_name' => (string) $candidate['teacher_name'],
                'teacher_reference' => (string) $candidate['teacher_reference'],
                'teacher_email' => (string) $candidate['teacher_email'],
                'active_lessons' => (int) $candidate['active_lessons'],
                'statement_id' => null,
                'state' => 'skipped',
                'reason' => null,
                'emailed' => false,
                'email_reason' => null,
            );
            if ((string) ($candidate['reason'] ?? '') !== '') {
                $result['state'] = 'failed';
                $result['reason'] = (string) $candidate['reason'];
                $counts['failed']++;
                $results[] = $result;
                continue;
            }
            try {
                $statementId = null;
                $state = 'draft';
                $existingId = (int) ($candidate['existing_statement_id'] ?? 0);
                $existingState = (string) ($candidate['existing_state'] ?? '');
                $exactExisting = $existingId > 0
                    && (string) ($candidate['existing_period']['start'] ?? '') === (string) $preview['period_start']
                    && (string) ($candidate['existing_period']['end'] ?? '') === (string) $preview['period_end'];
                if ($existingId > 0) {
                    if ($exactExisting && $existingState === 'draft' && $issue) {
                        $this->statements->issue($existingId, $batchKey . ':issue:' . $teacherId);
                        $statementId = $existingId;
                        $state = 'issued';
                        $counts['issued']++;
                    } elseif ($exactExisting && $existingState === 'issued') {
                        $result['state'] = 'skipped';
                        $result['reason'] = 'statement_already_issued';
                        $result['statement_id'] = $existingId;
                        if ($sendEmail && !$this->repository->hasEmailEvent($existingId)) {
                            $mail = $this->email->send($existingId);
                            $result['emailed'] = (bool) ($mail['emailed'] ?? false);
                            $result['email_reason'] = $mail['reason'] ?? null;
                            if ($result['emailed']) $counts['emailed']++;
                        }
                        $counts['skipped']++;
                        $results[] = $result;
                        continue;
                    } else {
                        $result['state'] = 'skipped';
                        $result['reason'] = $exactExisting ? 'statement_already_exists' : 'statement_period_overlap';
                        $result['statement_id'] = $existingId;
                        $counts['skipped']++;
                        $results[] = $result;
                        continue;
                    }
                } else {
                    $draft = $this->statements->draft($teacherId, (string) $preview['period_start'], (string) $preview['period_end'], $batchKey . ':draft:' . $teacherId);
                    $statementId = (int) $draft['statement_id'];
                    $counts['drafted']++;
                    if ($issue) {
                        $this->statements->issue($statementId, $batchKey . ':issue:' . $teacherId);
                        $state = 'issued';
                        $counts['issued']++;
                    }
                }
                $result['statement_id'] = $statementId;
                $result['state'] = $state;
                if ($sendEmail && $state === 'issued' && $statementId !== null) {
                    $mail = $this->email->send($statementId);
                    $result['emailed'] = (bool) ($mail['emailed'] ?? false);
                    $result['email_reason'] = $mail['reason'] ?? null;
                    if ($result['emailed']) $counts['emailed']++;
                }
            } catch (\Throwable $error) {
                $result['state'] = 'failed';
                $result['reason'] = $this->reason($error);
                $counts['failed']++;
            }
            $results[] = $result;
        }
        return array(
            'month' => $preview['month'],
            'period_start' => $preview['period_start'],
            'period_end' => $preview['period_end'],
            'issue' => $issue,
            'email' => $sendEmail,
            'generated_at' => gmdate('Y-m-d H:i:s'),
            'summary' => $counts,
            'teachers' => $results,
        );
    }

    /** @return array{month:string,period_start:string,period_end:string} */
    private function period(string $month): array {
        $month = trim($month);
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month) !== 1) throw new \InvalidArgumentException('statement_batch_month_invalid');
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01', new \DateTimeZone('UTC'));
        if (!$start) throw new \InvalidArgumentException('statement_batch_month_invalid');
        $end = $start->modify('+1 month');
        if ($end->format('Y-m-d H:i:s') > gmdate('Y-m-d H:i:s')) throw new \InvalidArgumentException('statement_period_not_elapsed');
        return array('month' => $month, 'period_start' => $start->format('Y-m-d H:i:s'), 'period_end' => $end->format('Y-m-d H:i:s'));
    }

    private function reason(\Throwable $error): string {
        $message = trim($error->getMessage());
        return preg_match('/^[a-z0-9_]{3,64}$/D', $message) === 1 ? $message : 'statement_batch_failed';
    }
}
