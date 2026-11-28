<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Application\Checkout\StripeCheckoutReconciliationService;
use Delnavazan\Platform\Core\Infrastructure\Repository\CommercialPaymentRepository;
use Delnavazan\Platform\Core\Infrastructure\Repository\PostIntroRepository;
use Delnavazan\Platform\Integrations\{PostIntroActivationWorker,PostIntroSettlementHook};

/**
 * Narrow, idempotent recovery for a paid post-intro Enrolment.
 *
 * A recovery never invents a payment, Term, Teacher Assignment or Lesson. It first proves the
 * authoritative settlement (or recovers the exact Stripe Checkout settlement through the existing
 * authenticated reconciliation service), then reuses the existing activation worker.
 */
final class PostIntroRecoveryService {
    public function __construct(
        private ?PostIntroRepository $repository = null,
        private ?PostIntroFulfilmentReadService $fulfilment = null,
        private ?StripeCheckoutReconciliationService $reconciliation = null
    ) {
        $this->repository ??= new PostIntroRepository();
        $this->fulfilment ??= new PostIntroFulfilmentReadService();
        $this->reconciliation ??= new StripeCheckoutReconciliationService();
    }

    /** @return array<string,mixed> */
    public function inspect(int $enrolmentId): array {
        if (!current_user_can('dzn_manage_post_intro_automation')) throw new \RuntimeException('Unauthorized');
        $status = $this->fulfilment->forEnrolment($enrolmentId);
        $status['recent_webhook_refusals'] = $this->fulfilment->webhookRefusals(5);
        $status['recovery_actions'] = $this->actions($status);
        return $status;
    }

    /** @return array<string,mixed> */
    public function recover(int $enrolmentId): array {
        if (!current_user_can('dzn_manage_post_intro_automation')) throw new \RuntimeException('Unauthorized');
        $status = $this->fulfilment->forEnrolment($enrolmentId);
        if ($status['fulfilment_state'] === 'completed') {
            return array('state' => 'already_completed', 'status' => $status);
        }
        if ((int) $status['preparation_id'] < 1) throw new \RuntimeException('post_intro_preparation_missing');

        if (!$status['payment_verified']) {
            if (!current_user_can('dzn_manage_payment_execution')) throw new \RuntimeException('Unauthorized');
            $attemptUid = trim((string) ($status['checkout_session_uid'] ?? ''));
            if ($attemptUid === '') throw new \RuntimeException('verified_payment_required');
            $reconciled = $this->reconciliation->reconcile(array('attempt_uid' => $attemptUid));
            if (($reconciled['state'] ?? '') !== 'settled') {
                $reason = preg_match('/^[a-z0-9_]{1,64}$/D', (string) ($reconciled['reason_code'] ?? '')) === 1
                    ? (string) $reconciled['reason_code']
                    : 'checkout_reconciliation_unavailable';
                throw new \RuntimeException('checkout_reconciliation_' . $reason);
            }
            $status = $this->fulfilment->forEnrolment($enrolmentId);
            if (!$status['payment_verified']) throw new \RuntimeException('verified_payment_required');
        }

        // An accepted evidence row from an earlier reconciliation may predate its settlement row.
        // Align only the platform ingestion timestamp so the canonical commitment timeline is valid;
        // provider amount/currency/occurrence/account facts are never changed.
        $settlementEvidenceId = (int) ($status['settlement_evidence_id'] ?? 0);
        if ($settlementEvidenceId > 0) {
            (new CommercialPaymentService())->alignAcceptedEvidenceIngestionTime(
                $settlementEvidenceId,
                'post-intro-align-evidence-' . $settlementEvidenceId
            );
        }

        if (!$status['schedule_ready']) throw new \RuntimeException('authoritative_schedule_missing');

        if ((int) $status['job_id'] < 1) {
            PostIntroSettlementHook::handle((int) $status['purchase_id']);
            $status = $this->fulfilment->forEnrolment($enrolmentId);
            if ((int) $status['job_id'] < 1) throw new \RuntimeException('post_intro_job_unavailable');
        }

        if ($status['job_state'] === 'completed') {
            if ($status['fulfilment_state'] === 'awaiting_next_instalment') {
                return array('state' => 'awaiting_next_instalment', 'status' => $status);
            }
            if ($status['fulfilment_state'] !== 'completed') throw new \RuntimeException('activation_state_inconsistent');
            return array('state' => 'already_completed', 'status' => $status);
        }

        if (in_array($status['job_state'], array('failed', 'retryable'), true)) {
            PostIntroActivationWorker::requeue((int) $status['job_id']);
            $status = $this->fulfilment->forEnrolment($enrolmentId);
        }
        if (!in_array($status['job_state'], array('queued', 'retryable'), true)) {
            throw new \RuntimeException($status['job_state'] === 'running' ? 'post_intro_job_in_progress' : 'post_intro_job_not_due');
        }

        $run = PostIntroActivationWorker::runJob((int) $status['job_id']);
        $status = $this->fulfilment->forEnrolment($enrolmentId);
        return array('state' => $status['fulfilment_state'], 'job' => $run, 'status' => $status);
    }

    /**
     * Queue the missing activation job for a paid preparation and run the existing worker.
     *
     * The settlement hook is the authority that normally creates the job. It is safe to replay:
     * it locks the purchase, raises the funding target from settled obligations and never charges.
     *
     * @return array{state:string,job_id:int}
     */
    public function queueActivation(int $preparationId): array {
        if (!current_user_can('dzn_manage_post_intro_automation')) throw new \RuntimeException('Unauthorized');
        $preparation = $this->repository->preparation($preparationId);
        if (!$preparation) throw new \InvalidArgumentException('post_intro_preparation_not_found');
        if ((string) $preparation->state !== 'payment_received') throw new \InvalidArgumentException('post_intro_preparation_not_paid');
        $offerId = (int) ($preparation->offer_id ?? 0);
        $purchase = $offerId > 0 ? $this->repository->purchaseForOffer($offerId) : null;
        if (!$purchase) throw new \RuntimeException('commercial_purchase_required');
        // Re-prove the settlement on the server. The preparation state is a checkpoint, not the
        // payment authority; a forged or stale request must never progress an unpaid purchase.
        $obligations = $this->repository->obligationsForOffer($offerId);
        $obligation = $obligations[0] ?? null;
        $settlement = $obligation ? (new CommercialPaymentRepository())->settlementForObligation((int) $obligation->id) : null;
        if ($settlement === null) throw new \RuntimeException('verified_payment_required');
        PostIntroSettlementHook::handle((int) $purchase->id);
        $job = $this->repository->activeJobForPurchase((int) $purchase->id);
        if (!$job) throw new \RuntimeException('post_intro_job_unavailable');
        PostIntroActivationWorker::run();
        $fresh = $this->repository->job((int) $job->id);
        return array('state' => (string) ($fresh->state ?? 'queued'), 'job_id' => (int) $job->id);
    }

    /** @return list<string> */
    private function actions(array $status): array {
        if ($status['fulfilment_state'] === 'completed') return array();
        $actions = array();
        if (!$status['payment_verified'] && trim((string) ($status['checkout_session_uid'] ?? '')) !== '') {
            $actions[] = 'reconcile_checkout';
        }
        if (!$status['schedule_ready']) $actions[] = 'resolve_authoritative_schedule';
        if ($status['payment_verified'] && in_array($status['job_state'], array('failed', 'retryable'), true)) {
            $actions[] = 'retry_activation';
        } elseif ($status['payment_verified'] && in_array($status['job_state'], array('queued', 'retryable'), true)) {
            $actions[] = 'run_activation';
        } elseif ($status['payment_verified'] && (int) $status['job_id'] < 1) {
            $actions[] = 'queue_activation';
        }
        return array_values(array_unique($actions));
    }
}
