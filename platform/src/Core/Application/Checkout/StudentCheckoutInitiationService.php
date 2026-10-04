<?php
namespace Delnavazan\Platform\Core\Application\Checkout;

use Delnavazan\Platform\Core\Application\{CommercialIdempotency,CommercialSupport};
use Delnavazan\Platform\Core\Application\PaymentExecution\PaymentExecutionIdempotency;
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
    // Stripe retains idempotency results for at least 24 hours; stop replaying with margin before pruning.
    private const PROVIDER_IDEMPOTENCY_REPLAY_SECONDS = 23 * 60 * 60;

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
            if ($active && (string) $active->state === 'open' && $this->expired($active, $now)) {
                $this->sessions->close((int) $active->id, 'expired', 'provider_session_expired', $now);
                $active = null;
            }
            if ($active) {
                if ((int) $active->student_id !== $studentId
                    || (int) $active->offer_id !== (int) $offer->id
                    || (int) $active->amount_minor !== (int) $obligation->amount_minor
                    || strtoupper((string) $active->currency) !== strtoupper((string) $obligation->currency)
                ) {
                    // Commercial facts changed after the attempt was made. The old payload may already
                    // exist at Stripe, so do not reuse its key with different parameters or replace it.
                    $this->authority->commit();
                    return array('checkout_state' => 'unavailable', 'redirect_url' => null);
                }
                $createdAt = strtotime((string) $active->created_at . ' UTC');
                if ($createdAt === false || time() - $createdAt >= self::PROVIDER_IDEMPOTENCY_REPLAY_SECONDS) {
                    // Stripe may prune an older key. Keep the unresolved attempt active for reconciliation
                    // rather than risk creating a second provider session with the same key.
                    $this->authority->commit();
                    return array('checkout_state' => 'pending', 'redirect_url' => null);
                }
                // Re-drive the same durable attempt with its same server-owned provider key. A prior
                // timeout may have created the provider session without returning its response.
                $sessionId = (int) $active->id;
                $key = $this->providerIdempotencyKey((string) $obligation->uid, (string) $active->uid);
                $request = $this->request($studentId, $offer, $obligation, $key);
                $this->authority->commit();
            } else {
                // The attempt UID is the stable generation identity used for every provider retry.
                $attemptUid = Identifier::uid();
                $key = $this->providerIdempotencyKey((string) $obligation->uid, $attemptUid);
                $request = $this->request($studentId, $offer, $obligation, $key);
                $sessionId = $this->sessions->insert(array(
                    'uid' => $attemptUid, 'student_id' => $studentId, 'offer_id' => (int) $offer->id,
                    'obligation_id' => (int) $obligation->id, 'amount_minor' => $request->amountMinor(),
                    'currency' => $request->currency(), 'state' => 'creating', 'active_slot' => 1,
                    'request_key_digest' => CommercialIdempotency::key($key), 'provider_key' => self::PROVIDER_KEY,
                    'provider_reference_digest' => null, 'created_at' => $now, 'created_by' => $actor,
                    'expires_at' => gmdate('Y-m-d H:i:s', time() + self::CREATION_WINDOW_SECONDS),
                    'closed_at' => null, 'close_reason' => null,
                ));
                $this->authority->commit();
            }
        } catch (\Throwable $e) {
            $this->authority->rollback();
            throw $e;
        }

        try {
            $result = $this->checkout->create($request);
        } catch (\Throwable) {
            // Provider outcome is ambiguous. Keep the attempt active so the next request reuses the
            // identical provider idempotency key instead of creating a second session.
            return array('checkout_state' => 'pending', 'redirect_url' => null);
        }
        if (($result['state'] ?? '') === 'open'
            && is_string($result['redirect_url'] ?? null)
            && $this->safeStripeUrl($result['redirect_url'])
            && is_string($result['provider_reference'] ?? null)
            && preg_match('/^cs_test_[A-Za-z0-9]+$/D', $result['provider_reference']) === 1
            && is_string($result['expires_at'] ?? null)
            && $this->validSqlUtc($result['expires_at'])
        ) {
            $this->authority->begin();
            try {
                $this->authority->lockAccountRoot($studentId, $actor);
                $this->sessions->recordOpen($sessionId, PaymentExecutionIdempotency::reference($result['provider_reference']), $result['expires_at']);
                $this->authority->commit();
                return array('checkout_state' => 'open', 'redirect_url' => $result['redirect_url']);
            } catch (\Throwable $e) {
                $this->authority->rollback();
                // The provider may already have created the session; leave the local attempt retryable.
                return array('checkout_state' => 'pending', 'redirect_url' => null);
            }
        }
        if (($result['state'] ?? '') === 'failed') {
            $reason = in_array((string) ($result['reason_code'] ?? ''), array('provider_request_rejected','checkout_provider_unconfigured'), true)
                ? (string) $result['reason_code'] : 'provider_request_rejected';
            $this->closeFailedCreation($sessionId, $studentId, $actor, $reason);
            return array('checkout_state' => 'unavailable', 'redirect_url' => null);
        }
        // `unavailable`, malformed success responses and transport ambiguity do not prove failure.
        return array('checkout_state' => 'pending', 'redirect_url' => null);
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

    private function providerIdempotencyKey(string $obligationUid, string $attemptUid): string {
        return hash('sha256', 'student_checkout_v1:' . $obligationUid . ':' . $attemptUid);
    }

    private function request(int $studentId, object $offer, object $obligation, string $key): CheckoutRequest {
        return new CheckoutRequest(
            $studentId,
            (int) $offer->id,
            (int) $obligation->id,
            (int) $obligation->amount_minor,
            (string) $obligation->currency,
            CommercialSupport::obligationReference((string) $offer->uid, (int) $obligation->obligation_sequence),
            $key
        );
    }

    private function safeStripeUrl(string $url): bool {
        $parts = parse_url($url);
        return is_array($parts)
            && ($parts['scheme'] ?? '') === 'https'
            && strtolower((string) ($parts['host'] ?? '')) === 'checkout.stripe.com'
            && !isset($parts['user']) && !isset($parts['pass']);
    }

    private function validSqlUtc(string $value): bool {
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value) !== 1) return false;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        return $date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
    }

    private function closeFailedCreation(int $sessionId, int $studentId, int $actor, string $reason): void {
        $this->authority->begin();
        try {
            $this->authority->lockAccountRoot($studentId, $actor);
            $this->sessions->close($sessionId, 'failed', $reason, gmdate('Y-m-d H:i:s'));
            $this->authority->commit();
        } catch (\Throwable $e) {
            $this->authority->rollback();
            throw $e;
        }
    }
}
