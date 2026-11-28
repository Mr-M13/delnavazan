<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\PostIntroRepository;

/**
 * Read-only projection of the post-intro preparations that still need an operator action.
 *
 * The Overview card and the protected Post-intro diagnostics surface render this one list, so
 * the number on the dashboard always names work that can be completed on the page it links to.
 * A preparation is excluded when its academic provisioning already owns a canonical Term and
 * Teacher Assignment, when its introduction was not delivered, or when it never reached the
 * automation workflow; those are history, not actionable exceptions.
 */
final class PostIntroAttentionReadService {
    public function __construct(private ?PostIntroRepository $repository = null) {
        $this->repository ??= new PostIntroRepository();
    }

    /**
     * @return list<array{
     *   preparation_id:int,booking_request_id:int,enrolment_id:int,reference:string,
     *   learner_name:string,enrolment_reference:string,state:string,exception_code:string,
     *   exception_detail:string,updated_at:string,reason_code:string,reason_label:string,
     *   action:string,job_id:int
     * }>
     */
    public function rows(int $limit = 250): array {
        if (!current_user_can('dzn_manage_post_intro_automation')) throw new \RuntimeException('Unauthorized');
        return self::compose(
            $this->repository->exceptionPreparations($limit),
            $this->repository->failedJobs($limit),
            $this->repository->paidPreparationsWithoutActivationJob($limit)
        );
    }

    /**
     * Merge the three actionable sources into one list. A preparation keeps the single action that
     * can actually move it forward: fixing the preparation wins over retrying an older job, and a
     * paid preparation without any job is queued rather than retried.
     *
     * @param list<object> $exceptionRows
     * @param list<object> $jobRows
     * @param list<object> $paidRows
     * @return list<array<string,mixed>>
     */
    public static function compose(array $exceptionRows, array $jobRows, array $paidRows): array {
        $rows = array();
        foreach ($exceptionRows as $row) {
            $id = (int) ($row->id ?? 0);
            if ($id < 1) continue;
            $rows[$id] = self::baseFromPreparation($row) + array(
                'reason_code' => 'preparation_exception',
                'reason_label' => 'Preparation stopped: ' . self::code((string) ($row->exception_code ?? ''), (string) ($row->state ?? 'exception')),
                'action' => 'retry_preparation',
                'job_id' => 0,
            );
        }
        foreach ($paidRows as $row) {
            $id = (int) ($row->id ?? 0);
            if ($id < 1 || isset($rows[$id])) continue;
            $rows[$id] = self::baseFromPreparation($row) + array(
                'reason_code' => 'activation_missing',
                'reason_label' => 'Payment verified but no activation job exists',
                'action' => 'queue_activation',
                'job_id' => 0,
            );
        }
        foreach ($jobRows as $job) {
            $id = (int) ($job->preparation_id ?? 0);
            if ($id < 1 || isset($rows[$id])) continue;
            $preparationState = (string) ($job->preparation_state ?? '');
            if (in_array($preparationState, array('activated', 'expired'), true)) continue;
            $jobState = (string) ($job->state ?? '');
            if (!in_array($jobState, array('failed', 'retryable', 'queued'), true)) continue;
            $rows[$id] = array(
                'preparation_id' => $id,
                'booking_request_id' => (int) ($job->booking_request_id ?? 0),
                'enrolment_id' => (int) ($job->enrolment_id ?? 0),
                'reference' => (string) ($job->reference_code ?? ''),
                'learner_name' => (string) ($job->learner_name ?? 'Learner'),
                'enrolment_reference' => (string) ($job->enrolment_reference ?? ''),
                'state' => $preparationState,
                'exception_code' => '',
                'exception_detail' => (string) ($job->last_error_detail ?? ''),
                'updated_at' => (string) ($job->updated_at ?? $job->preparation_updated_at ?? ''),
                'reason_code' => $jobState === 'failed' ? 'activation_failed' : 'activation_stalled',
                'reason_label' => $jobState === 'failed'
                    ? 'Activation failed: ' . self::code((string) ($job->last_error_code ?? ''), 'activation_failed')
                    : 'Activation is queued but overdue',
                'action' => 'retry_activation',
                'job_id' => (int) ($job->id ?? 0),
            );
        }
        $rows = array_values($rows);
        usort($rows, static function (array $a, array $b): int {
            return strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? ''));
        });
        return $rows;
    }

    /** @return array<string,mixed> */
    private static function baseFromPreparation(object $row): array {
        return array(
            'preparation_id' => (int) ($row->id ?? 0),
            'booking_request_id' => (int) ($row->booking_request_id ?? 0),
            'enrolment_id' => (int) ($row->enrolment_id ?? 0),
            'reference' => (string) ($row->reference_code ?? ''),
            'learner_name' => (string) ($row->learner_name ?? 'Learner'),
            'enrolment_reference' => (string) ($row->enrolment_reference ?? ''),
            'state' => (string) ($row->state ?? ''),
            'exception_code' => (string) ($row->exception_code ?? ''),
            'exception_detail' => (string) ($row->exception_detail ?? ''),
            'updated_at' => (string) ($row->updated_at ?? ''),
        );
    }

    private static function code(string $value, string $fallback): string {
        $value = trim($value);
        return $value !== '' ? $value : $fallback;
    }
}
