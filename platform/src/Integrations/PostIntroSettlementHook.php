<?php
namespace Delnavazan\Platform\Integrations;

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
        if ((string) $preparation->state === 'activated') return;
        if ($repository->activeJobForPurchase($purchaseId)) return;

        $now = gmdate('Y-m-d H:i:s');
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
                'completed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ));
        } catch (\Throwable $error) {
            if (!$repository->activeJobForPurchase($purchaseId)) throw $error;
        }

        // An expired hold is terminal for the Student action. The verified payment is still
        // recorded and the activation job is queued so the admin sees the exact late-capture
        // exception; the preparation state is never silently rewritten to "payment received".
        if ((string) $preparation->state !== 'expired') {
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
