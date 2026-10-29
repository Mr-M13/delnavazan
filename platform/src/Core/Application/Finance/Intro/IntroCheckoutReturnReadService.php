<?php
namespace Delnavazan\Platform\Core\Application\Finance\Intro;

use Delnavazan\Platform\Core\Infrastructure\Repository\IntroPaymentRepository;
use Delnavazan\Platform\Portals\PortalPrincipalResolver;

/** Owner-bound return status for a paid introductory Checkout attempt. */
final class IntroCheckoutReturnReadService {
    public function __construct(
        private ?IntroPaymentRepository $repository = null,
        private ?PortalPrincipalResolver $principals = null
    ) {
        $this->repository ??= new IntroPaymentRepository();
        $this->principals ??= new PortalPrincipalResolver();
    }

    /** @return array{payment_state:string,checkout_state:string,action:?string,retry_allowed:bool,obligation_uid:?string} */
    public function read(array $input): array {
        if (array_keys($input) !== array('attempt_uid')) throw new \InvalidArgumentException('checkout_unavailable');
        $uid = trim((string) ($input['attempt_uid'] ?? ''));
        if (preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D', $uid) !== 1) throw new \InvalidArgumentException('checkout_unavailable');
        $principal = $this->principals->resolve('student');
        $studentId = (int) ($principal['id'] ?? 0);
        $session = $this->repository->sessionByUid($uid);
        if ($studentId < 1 || !$session || (int) $session->student_id !== $studentId) throw new \InvalidArgumentException('checkout_unavailable');
        $obligation = $this->repository->obligation((int) $session->intro_obligation_id);
        if (!$obligation || (int) $obligation->student_id !== $studentId) throw new \InvalidArgumentException('checkout_unavailable');
        if ((string) $obligation->state === IntroPaymentService::STATE_PAID) return array('payment_state' => 'paid', 'checkout_state' => 'completed', 'action' => null, 'retry_allowed' => false, 'obligation_uid' => (string) $obligation->uid);
        if ((string) $obligation->state === IntroPaymentService::STATE_REFUNDED) return array('payment_state' => 'refunded', 'checkout_state' => 'completed', 'action' => null, 'retry_allowed' => false, 'obligation_uid' => (string) $obligation->uid);
        if (!in_array((string) $obligation->state, array(IntroPaymentService::STATE_PENDING, IntroPaymentService::STATE_FAILED), true)) return array('payment_state' => 'unavailable', 'checkout_state' => 'unavailable', 'action' => null, 'retry_allowed' => false, 'obligation_uid' => (string) $obligation->uid);
        $now = gmdate('Y-m-d H:i:s');
        $state = (string) $session->state;
        $expiredOpen = $state === 'open' && $session->expires_at !== null && (string) $session->expires_at <= $now;
        $checkoutState = $expiredOpen ? 'expired' : (in_array($state, array('creating', 'open', 'completed', 'expired', 'failed'), true) ? $state : 'unavailable');
        if ($expiredOpen || in_array($state, array('expired', 'failed'), true)) return array('payment_state' => 'required', 'checkout_state' => $checkoutState, 'action' => 'retry', 'retry_allowed' => true, 'obligation_uid' => (string) $obligation->uid);
        if ($state === 'open') return array('payment_state' => 'processing', 'checkout_state' => 'open', 'action' => 'continue', 'retry_allowed' => false, 'obligation_uid' => (string) $obligation->uid);
        if ($state === 'creating') return array('payment_state' => 'processing', 'checkout_state' => 'creating', 'action' => 'resume', 'retry_allowed' => true, 'obligation_uid' => (string) $obligation->uid);
        return array('payment_state' => 'processing', 'checkout_state' => $checkoutState, 'action' => null, 'retry_allowed' => false, 'obligation_uid' => (string) $obligation->uid);
    }
}
