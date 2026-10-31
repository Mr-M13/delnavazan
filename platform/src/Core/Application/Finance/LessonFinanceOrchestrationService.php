<?php
namespace Delnavazan\Platform\Core\Application\Finance;

use Delnavazan\Platform\Core\Infrastructure\Repository\CanonicalLessonScheduleRepository;

/**
 * Connects an already-completed payable Lesson to the existing Finance snapshot/payability
 * authorities.
 *
 * It calculates no money of its own and it never runs inside the completion transaction. A Finance
 * failure is recorded as pending; it never reverses attendance or completion. The canonical snapshot
 * command remains idempotent, so repeated completion/capture/drafting converges on one committed row.
 */
final class LessonFinanceOrchestrationService {
    public function __construct(
        private ?LessonFinanceSnapshotService $snapshots = null,
        private ?LessonPayabilityService $payability = null,
        private ?CanonicalLessonScheduleRepository $schedules = null
    ) {
        $this->snapshots ??= new LessonFinanceSnapshotService();
        $this->payability ??= new LessonPayabilityService();
        $this->schedules ??= new CanonicalLessonScheduleRepository();
    }

    /**
     * @return array{state:string,reason:?string,snapshot_id:?int,payability:?string,bonus_minor:int,total_minor:?int}
     */
    public function captureAfterCompletion(int $lessonId, string $trigger = 'completion'): array {
        $pending = array('state'=>'finance_pending','reason'=>null,'snapshot_id'=>null,'payability'=>null,'bonus_minor'=>0,'total_minor'=>null);
        try {
            $version = null;
            foreach ($this->schedules->versionsForLesson($lessonId) as $candidate) {
                if ((int) ($candidate->applicable_slot ?? 0) === 1) { $version = $candidate; break; }
            }
            if (!$version) {
                $pending['reason'] = 'occurrence_anchor_missing';
                return $pending;
            }
            $capture = $this->snapshots->capture($lessonId, 'lesson-finance-capture:' . $lessonId . ':' . (int) $version->id);
            $snapshotId = (int) ($capture['snapshot_id'] ?? 0);
            if ($snapshotId < 1) {
                $pending['reason'] = 'snapshot_missing_for_lesson';
                return $pending;
            }
            $state = 'captured';
            $disposition = null;
            try {
                $evaluation = $this->payability->evaluate($lessonId, 'lesson-payability-evaluate:' . $lessonId . ':' . $snapshotId);
                $disposition = (string) ($evaluation['disposition'] ?? 'pending');
            } catch (\Throwable $error) {
                $state = 'finance_pending';
                $pending['reason'] = $this->safeReason($error);
            }
            do_action('dzn_lesson_finance_orchestration_completed', $lessonId, $trigger, $state, $snapshotId, $disposition);
            return array(
                'state' => $state,
                'reason' => $state === 'captured' ? null : $pending['reason'],
                'snapshot_id' => $snapshotId,
                'payability' => $disposition,
                'bonus_minor' => (int) ($capture['golden_hour_bonus_minor'] ?? 0),
                'total_minor' => isset($capture['total_amount_minor']) ? (int) $capture['total_amount_minor'] : null,
            );
        } catch (\Throwable $error) {
            $pending['reason'] = $this->safeReason($error);
            do_action('dzn_lesson_finance_orchestration_failed', $lessonId, $trigger, $pending['reason'], get_class($error));
            return $pending;
        }
    }

    private function safeReason(\Throwable $error): string {
        $message = trim($error->getMessage());
        return preg_match('/^[a-z0-9_]{3,64}$/D', $message) === 1 ? $message : 'finance_pending';
    }
}
