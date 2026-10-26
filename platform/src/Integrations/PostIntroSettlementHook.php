<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Application\CommercialTermFundingService;
use Delnavazan\Platform\Core\Infrastructure\Repository\PostIntroRepository;
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Enqueue post-intro activation inside the verified settlement transaction.
 *
 * The job row commits atomically with the payment settlement. Any persistence error is thrown so
 * the payment webhook retries rather than leaving a paid obligation without an activation path.
 */
final class PostIntroSettlementHook {
    public static function register(): void {
        add_action('dzn_phase_2a2r1_after_settlement', array(__CLASS__, 'handle'), 10, 1);
    }

    public static function handle(int $purchaseId): void {
        $repository = new PostIntroRepository();
        $purchase = $repository->purchase($purchaseId);
        if (!$purchase) throw new \RuntimeException('commercial_purchase_required');
        $preparation = $repository->preparationByOffer((int) $purchase->offer_id);
        if (!$preparation) return;

        $now = gmdate('Y-m-d H:i:s');
        // The academic target is derived from the settled obligations, not from the current
        // preparation state or an earlier worker run. A later verified settlement therefore
        // raises the target even when the first activation already completed.
        $effective = (new CommercialTermFundingService())->effectiveSessions((int) $purchase->offer_id);
        $job = $repository->activeJobForPurchase($purchaseId, true);

        if ($job === null && $effective > 0) {
            try {
                $repository->insertJob(array(
                    'uid' => Identifier::uid(),
                    'purchase_id' => $purchaseId,
                    'preparation_id' => (int) $preparation->id,
                    'offer_id' => (int) $purchase->offer_id,
                    'entitlement_id' => null,
                    'state' => 'queued',
                    'attempt_count' => 0,
                    'next_attempt_at' => $now,
                    'lease_token_digest' => null,
                    'lease_expires_at' => null,
                    'last_error_code' => null,
                    'last_error_detail' => null,
                    'checkpoint' => 'paid',
                    'funding_target' => $effective,
                    'funding_materialised' => 0,
                    'completed_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ));
            } catch (\Throwable $error) {
                $job = $repository->activeJobForPurchase($purchaseId, true);
                if (!$job) throw $error;
            }
        }

        if (is_object($job)) {
            $target = max((int) $job->funding_target, $effective);
            $raised = $target > (int) $job->funding_target;
            $state = (string) $job->state;
            if ($state === 'running') {
                // Never clear a live worker's lease. It re-reads the target before completing and
                // requeues itself when more sessions became effective during its run.
                if ($raised) $repository->updateJob((int) $job->id, array('funding_target' => $target, 'next_attempt_at' => $now));
            } elseif ($raised || ($state === 'completed' && (int) $job->funding_materialised < $effective)) {
                $changes = array(
                    'state' => 'queued',
                    'funding_target' => $target,
                    'attempt_count' => 0,
                    'next_attempt_at' => $now,
                    'lease_token_digest' => null,
                    'lease_expires_at' => null,
                    'last_error_code' => null,
                    'last_error_detail' => null,
                    'completed_at' => null,
                    'checkpoint' => 'additional_funding',
                );
                $repository->updateJob((int) $job->id, $changes);
            }
        }

        // An expired hold is terminal for the Student action. The verified payment is still
        // recorded and the activation target is raised so the admin sees the exact late-capture
        // exception; the preparation state is never silently rewritten to "payment received".
        if (!in_array((string) $preparation->state, array('expired', 'activated'), true)) {
            $repository->updatePreparation((int) $preparation->id, array(
                'state' => 'payment_received',
                'exception_code' => null,
                'exception_detail' => null,
                'last_attempt_at' => $now,
                'updated_at' => $now,
            ));
        }
    }
}
