<?php
namespace Delnavazan\Platform\Core\Application\Finance\Statements;

use Delnavazan\Platform\Core\Application\Finance\{FinanceSupport};
use Delnavazan\Platform\Core\Infrastructure\Repository\StatementBatchRunRepository;
use Delnavazan\Platform\Core\Support\Identifier;
use Delnavazan\Platform\Core\Support\PersianCalendar;

/**
 * Recoverable draft-only statement scheduling on the Persian calendar.
 *
 * Default off. When enabled, a daily worker prepares drafts for the previous completed Persian
 * month using the Asia/Tehran half-open month boundary. It never issues or emails a statement:
 * those remain explicit administrator actions. One durable run row per Persian month makes the
 * job idempotent and gives an administrator a retryable history. A UTC calendar month is never
 * relabelled as Persian.
 */
final class TeacherStatementScheduleService {
    public const HOOK = 'dzn_monthly_statement_draft';
    public const OPTION_ENABLED = 'dzn_monthly_statement_draft_enabled';
    public const ACTOR_OPTION = 'dzn_monthly_statement_draft_actor';
    public const MANAGE_CAPABILITY = 'dzn_manage_finance_statements';
    public const VIEW_CAPABILITY = 'dzn_view_finance_authority';

    public function __construct(
        private ?TeacherStatementBatchService $batch = null,
        private ?StatementBatchRunRepository $runs = null
    ) {
        $this->batch ??= new TeacherStatementBatchService();
        $this->runs ??= new StatementBatchRunRepository();
    }

    public static function register(): void {
        add_action(self::HOOK, array(__CLASS__, 'runDue'));
        add_action('init', array(__CLASS__, 'schedule'));
    }
    public static function schedule(): void {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_event')) return;
        if ((string) get_option(self::OPTION_ENABLED, '0') !== '1') return;
        if (!wp_next_scheduled(self::HOOK)) wp_schedule_event(time() + 300, 'daily', self::HOOK);
    }
    public static function runDue(): void {
        $actor = (int) get_option(self::ACTOR_OPTION, 0);
        if ($actor < 1) return;
        $previous = (int) get_current_user_id();
        wp_set_current_user($actor);
        try { (new self())->runPreviousMonth(false); } catch (\Throwable) { /* the durable run row keeps the failure visible */ } finally { wp_set_current_user($previous > 0 ? $previous : 0); }
    }
    public function enabled(): bool { return (string) get_option(self::OPTION_ENABLED, '0') === '1'; }
    public function setEnabled(bool $enabled): void {
        FinanceSupport::requireCapability(self::MANAGE_CAPABILITY);
        update_option(self::OPTION_ENABLED, $enabled ? '1' : '0', false);
        if ($enabled) update_option(self::ACTOR_OPTION, max(0, (int) get_current_user_id()), false);
    }
    /** @return list<object> */
    public function history(int $limit = 20): array {
        FinanceSupport::requireCapability(self::VIEW_CAPABILITY);
        return $this->runs->recent($limit);
    }
    /** @return array<string,mixed> */
    public function runPreviousMonth(bool $requireEnabled = true): array {
        if ($requireEnabled && !$this->enabled()) return array('state' => 'disabled');
        $period = PersianCalendar::previousMonthForInstant(gmdate('Y-m-d H:i:s'), PersianCalendar::DEFAULT_TIMEZONE);
        return $this->runPersianMonth($period['key']);
    }
    /**
     * Legacy UTC month entry point retained for callers that explicitly want a UTC month.
     *
     * @return array<string,mixed>
     */
    public function runMonth(string $month): array {
        FinanceSupport::requireCapability(self::MANAGE_CAPABILITY);
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month) !== 1) throw new \InvalidArgumentException('statement_batch_month_invalid');
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01', new \DateTimeZone('UTC'));
        if (!$start) throw new \InvalidArgumentException('statement_batch_month_invalid');
        $end = $start->modify('+1 month');
        if ($end->format('Y-m-d H:i:s') > gmdate('Y-m-d H:i:s')) throw new \InvalidArgumentException('statement_period_not_elapsed');
        $period = array(
            'month' => $month,
            'period_key' => 'utc_month:' . $month,
            'period_label' => 'UTC month ' . $month,
            'calendar' => 'gregorian_utc',
            'timezone' => 'UTC',
            'period_start' => $start->format('Y-m-d H:i:s'),
            'period_end' => $end->format('Y-m-d H:i:s'),
        );
        return $this->runPeriod($period, 'utc_month:' . $month);
    }

    /** @return array<string,mixed> */
    public function runPersianMonth(string $month): array {
        FinanceSupport::requireCapability(self::MANAGE_CAPABILITY);
        $period = (new TeacherStatementBatchService())->persianPeriod($month);
        return $this->runPeriod($period, 'persian_month:' . $month);
    }

    /** @return array<string,mixed> */
    private function runPeriod(array $period, string $runKey): array {
        $month = (string) ($period['month'] ?? '');
        if ($month === '') throw new \InvalidArgumentException('statement_batch_month_invalid');
        $this->runs->begin();
        try {
            $run = $this->runs->byRunKey($runKey, true);
            if ($run && (string) $run->state === 'completed') { $this->runs->commit(); return array('state' => 'already_completed', 'run' => $run); }
            $now = gmdate('Y-m-d H:i:s');
            $actor = max(0, (int) get_current_user_id());
            if ($run && (string) $run->state === 'running' && $run->lease_expires_at !== null && (string) $run->lease_expires_at > $now) {
                $this->runs->commit();
                return array('state' => 'already_running', 'run' => $run);
            }
            if ($run) {
                $attempt = (int) $run->attempt_count + 1;
                $this->runs->update((int) $run->id, (int) $run->run_version, array(
                    'state' => 'running', 'attempt_count' => $attempt, 'last_error_code' => null,
                    'started_at' => $now, 'lease_expires_at' => gmdate('Y-m-d H:i:s', time() + 3600), 'updated_at' => $now, 'updated_by' => $actor,
                ));
                $runId = (int) $run->id;
            } else {
                $runId = $this->runs->insert(array(
                    'uid' => Identifier::uid(), 'run_key' => $runKey, 'period_month' => $month, 'mode' => 'draft_only',
                    'state' => 'running', 'attempt_count' => 1, 'last_error_code' => null, 'result_json' => null,
                    'started_at' => $now, 'completed_at' => null, 'lease_expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
                    'run_version' => 1, 'created_at' => $now, 'updated_at' => $now, 'created_by' => $actor, 'updated_by' => $actor,
                ));
            }
            $this->runs->commit();
        } catch (\Throwable $error) {
            $this->runs->rollback();
            throw $error;
        }
        $result = array();
        try {
            $result = $this->batch->runPeriod($period, array('issue' => '', 'email' => ''), 'scheduled-draft:' . $runKey . ':' . $runId);
            $state = 'completed'; $errorCode = null;
        } catch (\Throwable $error) {
            $state = 'failed'; $errorCode = preg_match('/^[a-z0-9_]{3,64}$/D', trim($error->getMessage())) === 1 ? trim($error->getMessage()) : 'statement_batch_failed';
        }
        $finished = gmdate('Y-m-d H:i:s');
        $this->runs->begin();
        try {
            $fresh = $this->runs->find($runId, true);
            if (!$fresh) throw new \RuntimeException('statement_batch_run_required');
            $this->runs->update($runId, (int) $fresh->run_version, array(
                'state' => $state, 'last_error_code' => $errorCode,
                'result_json' => $result === array() ? null : wp_json_encode($result),
                'completed_at' => $finished, 'lease_expires_at' => null, 'updated_at' => $finished, 'updated_by' => max(0, (int) get_current_user_id()),
            ));
            $this->runs->commit();
        } catch (\Throwable $error) {
            $this->runs->rollback();
            throw $error;
        }
        return array('state' => $state, 'run_id' => $runId, 'month' => $month, 'result' => $result);
    }
}
