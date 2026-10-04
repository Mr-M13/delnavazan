<?php
namespace Delnavazan\Platform\Core\Application\Checkout;

use Delnavazan\Platform\Core\Application\{CommercialIdempotency,CommercialSupport};
use Delnavazan\Platform\Core\Infrastructure\Repository\{CheckoutSessionRepository,CommercialAuthorityRepository,CommercialPaymentRepository};
use Delnavazan\Platform\Core\Support\Identifier;
use Delnavazan\Platform\Portals\PortalPrincipalResolver;

/**
 * Student-owned customer-checkout authority.
 *
 * The request identifies an obligation only.  Every commercially significant value is re-read
 * from the canonical offer and obligation while holding the existing commercial account root.
 * A hosted-checkout return is deliberately absent from this service: only verified provider
 * evidence may settle an obligation through CommercialPaymentService.
 */
final class StudentCheckoutInitiationService {
    private const PROVIDER_KEY = 'stripe';
    private const CREATION_WINDOW_SECONDS = 300;

    public function __construct(
        private ?CommercialAuthorityRepository $authority = null,
        private ?CommercialPaymentRepository $payments = null,
        private ?CheckoutSessionRepository $sessions = null,
        private ?CheckoutSessionPort $checkout = null,
        private ?PortalPrincipalResolver $principals = null
    ) {
        $this->authority ??= new CommercialAuthorityRepository();
        $this->payments ??= new CommercialPaymentRepository();
        $this->sessions ??= new CheckoutSessionRepository();
        $this->checkout ??= new \Delnavazan\Platform\Integrations\Payment\Stripe\StripeCheckoutAdapter();
        $this->principals ??= new PortalPrincipalResolver();
    }

    /** @return array{checkout_state:string,redirect_url:?string} */
    public function initiate(array $input): array {
        $obligationUid = $this->obligationUid($input);
        $principal = $this->principals->resolve('student');
        $studentId = (int) $principal['id'];
        $actor = (int) get_current_user_id();
        if ($studentId < 1 || $actor < 1) {
            throw new \InvalidArgumentException('checkout_unavailable');
        }

        if ($this->checkout->key() !== self::PROVIDER_KEY) {
            throw new \InvalidArgumentException('checkout_unavailable');
        }

        $request = null;
        $sessionId = 0;
        $this->authority->begin();
        try {
            // This is the pre-existing commercial serialisation root.  Checkout never creates a
            // competing money lock and never takes it after another commercial lock.
            $this->authority->lockAccountRoot($studentId, $actor);
            $obligation = $this->authority->obligationByUid($obligationUid, true);
            if (!$obligation) {
                throw new \InvalidArgumentException('checkout_unavailable');
            }
            $offer = $this->authority->offer((int) $obligation->offer_id, true);
            $this->assertPayable($studentId, $offer, $obligation);
            if ($this->payments->settlementForObligation((int) $obligation->id, true)) {
                throw new \InvalidArgumentException('checkout_unavailable');
            }

            $now = gmdate('Y-m-d H:i:s');
            $active = $this->sessions->activeForObligation((int) $obligation->id, true);
            if ($active && $this->expired($active, $now)) {
                $this->sessions->close((int) $active->id, 'expired', 'creation_window_elapsed', $now);
                $active = null;
            }
            if ($active) {
                $this->authority->commit();
                return array('checkout_state' => 'pending', 'redirect_url' => null);
            }

            $previous = $this->sessions->latestForObligation((int) $obligation->id, true);
            $generation = $previous ? (string) $previous->uid : 'initial';
            // The browser supplies no idempotency material.  This key is deterministically rebuilt
            // from canonical state; a closed historical session advances the next server generation.
            $key = hash('sha256', 'student_checkout_v1:' . (string) $obligation->uid . ':' . $generation);
            $request = new CheckoutRequest(
                $studentId,
                (int) $offer->id,
                (int) $obligation->id,
                (int) $obligation->amount_minor,
                (string) $obligation->currency,
                CommercialSupport::obligationReference((string) $offer->uid, (int) $obligation->obligation_sequence),
                $key
            );
            $sessionId = $this->sessions->insert(array(
                'uid' => Identifier::uid(), 'student_id' => $studentId, 'offer_id' => (int) $offer->id,
                'obligation_id' => (int) $obligation->id, 'amount_minor' => $request->amountMinor(),
                'currency' => $request->currency(), 'state' => 'creating', 'active_slot' => 1,
                'request_key_digest' => CommercialIdempotency::key($key), 'provider_key' => self::PROVIDER_KEY,
                'provider_reference_digest' => null, 'created_at' => $now, 'created_by' => $actor,
                'expires_at' => gmdate('Y-m-d H:i:s', time() + self::CREATION_WINDOW_SECONDS),
                'closed_at' => null, 'close_reason' => null,
            ));
            $this->authority->commit();
        } catch (\Throwable $e) {
            $this->authority->rollback();
            throw $e;
        }

        // The current adapter is intentionally network-inert.  When the test-mode activation slice
        // replaces that behaviour, it must persist/reconcile provider material through the approved
        // provider mapping and vault boundaries before returning an open hosted URL.
        $result = $this->checkout->create($request);
        if (($result['state'] ?? '') === 'open' && is_string($result['redirect_url'] ?? null) && $result['redirect_url'] !== '') {
            throw new \RuntimeException('checkout_provider_persistence_required');
        }
        $this->closeFailedCreation($sessionId, $studentId, $actor);
        return array('checkout_state' => 'unavailable', 'redirect_url' => null);
    }

    private function obligationUid(array $input): string {
        if (array_keys($input) !== array('obligation_uid')) {
            throw new \InvalidArgumentException('checkout_unavailable');
        }
        $uid = trim((string) ($input['obligation_uid'] ?? ''));
        if (preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D', $uid) !== 1) {
            throw new \InvalidArgumentException('checkout_unavailable');
        }
        return $uid;
    }

    private function assertPayable(int $studentId, ?object $offer, object $obligation): void {
        if (!$offer || (int) $offer->beneficiary_student_id !== $studentId || (int) $offer->id !== (int) $obligation->offer_id) {
            throw new \InvalidArgumentException('checkout_unavailable');
        }
        if ((string) $offer->state !== 'issued' || ($offer->expires_at !== null && (string) $offer->expires_at <= gmdate('Y-m-d H:i:s'))) {
            throw new \InvalidArgumentException('checkout_unavailable');
        }
        if ((int) $obligation->amount_minor < 1 || preg_match('/^[A-Z]{3}$/D', (string) $obligation->currency) !== 1) {
            throw new \InvalidArgumentException('checkout_unavailable');
        }
    }

    private function expired(object $session, string $now): bool {
        return $session->expires_at !== null && (string) $session->expires_at <= $now;
    }

    private function closeFailedCreation(int $sessionId, int $studentId, int $actor): void {
        $this->authority->begin();
        try {
            $this->authority->lockAccountRoot($studentId, $actor);
            $this->sessions->close($sessionId, 'failed', 'checkout_provider_unconfigured', gmdate('Y-m-d H:i:s'));
            $this->authority->commit();
        } catch (\Throwable $e) {
            $this->authority->rollback();
            throw $e;
        }
    }
}
