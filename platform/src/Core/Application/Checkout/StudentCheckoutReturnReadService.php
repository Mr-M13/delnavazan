<?php
namespace Delnavazan\Platform\Core\Application\Checkout;

use Delnavazan\Platform\Core\Infrastructure\Repository\{CheckoutSessionRepository,CommercialAuthorityRepository,CommercialPaymentRepository};
use Delnavazan\Platform\Portals\PortalPrincipalResolver;

/** Authenticated, owner-bound return status. Browser parameters identify an attempt only. */
final class StudentCheckoutReturnReadService {
    public function __construct(
        private ?CheckoutSessionRepository $sessions = null,
        private ?CommercialAuthorityRepository $authority = null,
        private ?CommercialPaymentRepository $payments = null,
        private ?PortalPrincipalResolver $principals = null
    ) {
        $this->sessions ??= new CheckoutSessionRepository();
        $this->authority ??= new CommercialAuthorityRepository();
        $this->payments ??= new CommercialPaymentRepository();
        $this->principals ??= new PortalPrincipalResolver();
    }

    /** @return array{payment_state:string,checkout_state:string,action:?string,retry_allowed:bool,obligation_uid:?string} */
    public function read(array $input): array {
        if (array_keys($input) !== array('attempt_uid')) throw new \InvalidArgumentException('checkout_unavailable');
        $uid = trim((string) ($input['attempt_uid'] ?? ''));
        if (preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D', $uid) !== 1) throw new \InvalidArgumentException('checkout_unavailable');
        $principal = $this->principals->resolve('student');
        $studentId = (int) ($principal['id'] ?? 0);
        $session = $this->sessions->byUid($uid);
        if ($studentId < 1 || !$session || (int) $session->student_id !== $studentId) throw new \InvalidArgumentException('checkout_unavailable');

        $obligation = $this->authority->obligation((int) $session->obligation_id);
        $offer = $obligation ? $this->authority->offer((int) $obligation->offer_id) : null;
        if (!$obligation || !$offer || (int) $obligation->offer_id !== (int) $session->offer_id
            || (int) $offer->id !== (int) $session->offer_id || (int) $offer->beneficiary_student_id !== $studentId) {
            throw new \InvalidArgumentException('checkout_unavailable');
        }
        if ($this->payments->settlementForObligation((int) $session->obligation_id)) {
            return array('payment_state' => 'paid', 'checkout_state' => 'completed', 'action' => null, 'retry_allowed' => false, 'obligation_uid' => (string) $obligation->uid);
        }
        $payable = $obligation && $offer
            && (string) $offer->state === 'issued'
            && ($offer->expires_at === null || (string) $offer->expires_at > gmdate('Y-m-d H:i:s'))
            && (int) $session->amount_minor === (int) $obligation->amount_minor
            && strtoupper((string) $session->currency) === strtoupper((string) $obligation->currency)
            && (int) $obligation->amount_minor > 0
            && preg_match('/^[A-Z]{3}$/D', (string) $obligation->currency) === 1;

        $state = (string) $session->state;
        $expiredOpen = $state === 'open' && $session->expires_at !== null && (string) $session->expires_at <= gmdate('Y-m-d H:i:s');
        $checkoutState = $expiredOpen ? 'expired' : (in_array($state, array('creating', 'open', 'completed', 'expired', 'failed'), true) ? $state : 'unavailable');
        if (!$payable || $checkoutState === 'unavailable') return array('payment_state' => 'unavailable', 'checkout_state' => $checkoutState, 'action' => null, 'retry_allowed' => false, 'obligation_uid' => (string) $obligation->uid);
        if ($expiredOpen || in_array($state, array('expired', 'failed'), true)) {
            return array('payment_state' => 'required', 'checkout_state' => $checkoutState, 'action' => 'retry', 'retry_allowed' => true, 'obligation_uid' => (string) $obligation->uid);
        }
        if ($state === 'open') return array('payment_state' => 'processing', 'checkout_state' => 'open', 'action' => 'continue', 'retry_allowed' => false, 'obligation_uid' => (string) $obligation->uid);
        if ($state === 'creating') return array('payment_state' => 'processing', 'checkout_state' => 'creating', 'action' => 'resume', 'retry_allowed' => true, 'obligation_uid' => (string) $obligation->uid);
        return array('payment_state' => 'processing', 'checkout_state' => $checkoutState, 'action' => null, 'retry_allowed' => false, 'obligation_uid' => (string) $obligation->uid);
    }
}
