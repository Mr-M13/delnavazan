<?php
namespace Delnavazan\Platform\Core\Application\Finance\Intro;

use Delnavazan\Platform\Core\Application\Checkout\CheckoutRequest;
use Delnavazan\Platform\Core\Application\PaymentExecution\PaymentExecutionIdempotency;
use Delnavazan\Platform\Core\Application\ProviderReferenceVault;
use Delnavazan\Platform\Core\Infrastructure\Repository\{IntroPaymentRepository,ProviderIntegrationRepository};
use Delnavazan\Platform\Core\Support\Identifier;
use Delnavazan\Platform\Portals\PortalPrincipalResolver;

/** Student-owned Stripe Checkout for one paid introductory lesson obligation. */
final class IntroCheckoutInitiationService {
    private const PROVIDER_KEY = 'stripe';
    private const CREATION_WINDOW_SECONDS = 300;
    private const PROVIDER_IDEMPOTENCY_REPLAY_SECONDS = 23 * 60 * 60;

    public function __construct(
        private ?IntroPaymentRepository $repository = null,
        private ?object $checkout = null,
        private ?ProviderReferenceVault $vault = null,
        private ?ProviderIntegrationRepository $references = null,
        private ?PortalPrincipalResolver $principals = null
    ) {
        $this->repository ??= new IntroPaymentRepository();
        $this->checkout ??= new \Delnavazan\Platform\Integrations\Payment\Stripe\StripeCheckoutAdapter();
        $this->vault ??= new ProviderReferenceVault();
        $this->references ??= new ProviderIntegrationRepository();
        $this->principals ??= new PortalPrincipalResolver();
    }

    /** @return array{checkout_state:string,redirect_url:?string} */
    public function initiate(array $input): array {
        if (array_keys($input) !== array('intro_obligation_uid')) throw new \InvalidArgumentException('checkout_unavailable');
        $uid = trim((string) ($input['intro_obligation_uid'] ?? ''));
        if (preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D', $uid) !== 1) throw new \InvalidArgumentException('checkout_unavailable');
        $principal = $this->principals->resolve('student');
        $studentId = (int) ($principal['id'] ?? 0);
        $actor = max(0, (int) get_current_user_id());
        if ($studentId < 1 || $actor < 1) throw new \InvalidArgumentException('checkout_unavailable');
        if (!is_object($this->checkout) || !method_exists($this->checkout, 'key') || (string) $this->checkout->key() !== self::PROVIDER_KEY) throw new \InvalidArgumentException('checkout_unavailable');

        $sessionId = 0;
        $request = null;
        $this->repository->begin();
        try {
            $obligation = $this->repository->obligationByUid($uid, true);
            if (!$obligation || (int) $obligation->student_id !== $studentId || (string) $obligation->state !== IntroPaymentService::STATE_PENDING) throw new \InvalidArgumentException('checkout_unavailable');
            $confirmation = $this->confirmation((int) $obligation->confirmation_id);
            if (!$confirmation || (string) $confirmation->state !== 'awaiting_payment' || (int) $confirmation->booking_request_id !== (int) $obligation->booking_request_id || !$this->bookingOwnedBy((int) $confirmation->booking_request_id, $studentId)) throw new \InvalidArgumentException('checkout_unavailable');
            $now = gmdate('Y-m-d H:i:s');
            $active = $this->repository->activeSession((int) $obligation->id, true);
            if ($active && (string) $active->state === 'open' && $this->expired($active, $now)) {
                $this->repository->closeSession((int) $active->id, 'expired', 'provider_session_expired', $now);
                $active = null;
            }
            if ($active) {
                if (!in_array((string) $active->state, array('creating', 'open'), true)) { $this->repository->commit(); return array('checkout_state' => 'unavailable', 'redirect_url' => null); }
                if ((int) $active->student_id !== $studentId
                    || (int) $active->amount_minor !== (int) $obligation->amount_minor
                    || strtoupper((string) $active->currency) !== strtoupper((string) $obligation->currency)) {
                    $this->repository->commit();
                    return array('checkout_state' => 'unavailable', 'redirect_url' => null);
                }
                if ((string) $active->state === 'open') {
                    $stored = $this->storedReferences((int) $active->id);
                    if ($stored !== null) {
                        $this->repository->commit();
                        return array('checkout_state' => 'open', 'redirect_url' => (string) $stored['checkout_uri_reference']);
                    }
                }
                $createdAt = strtotime((string) $active->created_at . ' UTC');
                if ($createdAt === false || time() - $createdAt >= self::PROVIDER_IDEMPOTENCY_REPLAY_SECONDS) {
                    $this->repository->commit();
                    return array('checkout_state' => 'pending', 'redirect_url' => null);
                }
                $sessionId = (int) $active->id;
                $key = $this->providerIdempotencyKey((string) $obligation->reference_code, (string) $active->uid);
                $request = $this->request($studentId, $obligation, $key, (string) $active->uid);
                $this->repository->commit();
            } else {
                $attemptUid = Identifier::uid();
                $key = $this->providerIdempotencyKey((string) $obligation->reference_code, $attemptUid);
                $request = $this->request($studentId, $obligation, $key, $attemptUid);
                $sessionId = $this->repository->insertSession(array(
                    'uid' => $attemptUid, 'intro_obligation_id' => (int) $obligation->id, 'student_id' => $studentId,
                    'amount_minor' => (int) $obligation->amount_minor, 'currency' => (string) $obligation->currency,
                    'state' => 'creating', 'active_slot' => 1,
                    'request_key_digest' => hash('sha256', 'intro-checkout:' . $key), 'provider_key' => self::PROVIDER_KEY,
                    'provider_reference_digest' => null, 'created_at' => $now, 'created_by' => $actor,
                    'expires_at' => gmdate('Y-m-d H:i:s', time() + self::CREATION_WINDOW_SECONDS), 'closed_at' => null, 'close_reason' => null,
                ));
                $this->repository->commit();
            }
        } catch (\Throwable $error) {
            $this->repository->rollback();
            throw $error;
        }

        try { $result = $this->checkout->create($request); }
        catch (\Throwable) { return array('checkout_state' => 'pending', 'redirect_url' => null); }
        if (($result['state'] ?? '') === 'open'
            && is_string($result['redirect_url'] ?? null) && $this->safeStripeUrl((string) $result['redirect_url'])
            && is_string($result['provider_reference'] ?? null) && preg_match('/^cs_test_[A-Za-z0-9]+$/D', (string) $result['provider_reference']) === 1
            && is_string($result['expires_at'] ?? null) && $this->validUtc((string) $result['expires_at'])) {
            $this->repository->begin();
            try {
                $this->storeReferences($sessionId, (string) $result['provider_reference'], (string) $result['redirect_url'], $actor, gmdate('Y-m-d H:i:s'));
                $this->repository->recordOpen($sessionId, PaymentExecutionIdempotency::reference((string) $result['provider_reference']), (string) $result['expires_at']);
                $this->repository->commit();
                return array('checkout_state' => 'open', 'redirect_url' => (string) $result['redirect_url']);
            } catch (\Throwable) {
                $this->repository->rollback();
                return array('checkout_state' => 'pending', 'redirect_url' => null);
            }
        }
        if (($result['state'] ?? '') === 'failed') {
            $reason = in_array((string) ($result['reason_code'] ?? ''), array('provider_request_rejected', 'checkout_provider_unconfigured'), true) ? (string) $result['reason_code'] : 'provider_request_rejected';
            try { $this->repository->begin(); $this->repository->closeSession($sessionId, 'failed', $reason, gmdate('Y-m-d H:i:s')); $this->repository->commit(); } catch (\Throwable) { $this->repository->rollback(); }
            return array('checkout_state' => 'unavailable', 'redirect_url' => null);
        }
        return array('checkout_state' => 'pending', 'redirect_url' => null);
    }

    private function request(int $studentId, object $obligation, string $key, string $attemptUid): CheckoutRequest {
        return new CheckoutRequest(
            $studentId, (int) $obligation->id, (int) $obligation->id,
            (int) $obligation->amount_minor, (string) $obligation->currency,
            (string) $obligation->reference_code, $key, $attemptUid
        );
    }

    private function providerIdempotencyKey(string $reference, string $attemptUid): string {
        return hash('sha256', 'intro_checkout_v1:' . $reference . ':' . $attemptUid);
    }

    private function confirmation(int $id): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dzn_booking_request_confirmations WHERE id=%d", $id));
    }

    private function bookingOwnedBy(int $bookingRequestId, int $studentId): bool {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}dzn_booking_requests WHERE id=%d AND student_id=%d AND lifecycle_status='submitted' AND privacy_erased_at IS NULL", $bookingRequestId, $studentId)) === 1;
    }

    private function expired(object $session, string $now): bool {
        return $session->expires_at !== null && (string) $session->expires_at <= $now;
    }

    private function safeStripeUrl(string $url): bool {
        $parts = parse_url($url);
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && strtolower((string) ($parts['host'] ?? '')) === 'checkout.stripe.com' && !isset($parts['user']) && !isset($parts['pass']);
    }

    private function validUtc(string $value): bool {
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value) !== 1) return false;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        return $date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
    }

    private function storeReferences(int $sessionId, string $providerReference, string $checkoutUri, int $actor, string $now): void {
        if ($sessionId < 1 || $actor < 1) throw new \InvalidArgumentException('checkout_reference_invalid');
        $sealed = $this->vault->seal('intro_checkout_session', $sessionId, array('provider_object_reference' => $providerReference, 'checkout_uri_reference' => $checkoutUri));
        $existing = $this->references->projectionSecret('intro_checkout_session', $sessionId, true);
        if ($existing) {
            $opened = $this->vault->open('intro_checkout_session', $sessionId, $existing);
            if (!hash_equals((string) ($opened['provider_object_reference'] ?? ''), $providerReference) || !hash_equals((string) ($opened['checkout_uri_reference'] ?? ''), $checkoutUri)) throw new \RuntimeException('checkout_reference_conflict');
            return;
        }
        $this->references->insertProjectionSecret(array_merge(array(
            'uid' => Identifier::uid(), 'mapping_kind' => 'intro_checkout_session', 'mapping_id' => $sessionId,
            'state' => 'active', 'active_slot' => 1, 'created_at' => $now, 'created_by' => $actor, 'retired_at' => null, 'retired_by' => null,
        ), $sealed));
    }

    private function storedReferences(int $sessionId): ?array {
        $row = $this->references->projectionSecret('intro_checkout_session', $sessionId);
        if (!$row || (string) ($row->state ?? '') !== 'active') return null;
        $opened = $this->vault->open('intro_checkout_session', $sessionId, $row);
        if (preg_match('/^cs_test_[A-Za-z0-9]+$/D', (string) ($opened['provider_object_reference'] ?? '')) !== 1 || !isset($opened['checkout_uri_reference'])) throw new \RuntimeException('checkout_reference_malformed');
        return $opened;
    }
}
