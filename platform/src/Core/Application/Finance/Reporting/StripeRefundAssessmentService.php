<?php
namespace Delnavazan\Platform\Core\Application\Finance\Reporting;

use Delnavazan\Platform\Core\Application\Finance\FinanceSupport;
use Delnavazan\Platform\Core\Application\Finance\ManualRefundService;
use Delnavazan\Platform\Core\Application\ProviderReferenceVault;

/**
 * Read-only feasibility assessment for an integrated Stripe refund.
 *
 * This class deliberately cannot create a refund. It records why the current evidence is not
 * sufficient to name a verified PaymentIntent or charge, and what authority would have to exist
 * before an administrator action could be designed safely. A Checkout Session id is not a
 * PaymentIntent: it is a container that may have a PaymentIntent, and the local evidence stores
 * only digested provider references. The encrypted Checkout reference is test-mode only.
 */
final class StripeRefundAssessmentService {
    public const VIEW_CAPABILITY = 'dzn_view_payment_execution_authority';
    public const MANAGE_CAPABILITY = 'dzn_manage_refund_reviews';

    private string $p;
    public function __construct() {
        global $wpdb;
        $this->p = $wpdb->prefix . 'dzn_';
    }

    /**
     * @return array<string,mixed>
     */
    public function assessObligation(int $obligationId): array {
        FinanceSupport::requireCapability(self::VIEW_CAPABILITY);
        global $wpdb;
        $out = array(
            'obligation_id' => $obligationId,
            'eligible' => false,
            'provider' => null,
            'mode' => 'unknown',
            'remaining_minor' => 0,
            'remaining_currency' => '',
            'verified_payment_intent' => false,
            'verified_charge' => false,
            'reasons' => array(),
            'required_before_action' => array(
                'A verified PaymentIntent or charge reference bound to the settled obligation and Stripe account.',
                'A live-mode refund capability distinct from the read-only payment view.',
                'A provider account binding whose mode matches the refund target and whose refund scope is authorised.',
                'A durable refund request with an idempotency key, duplicate detection and remaining-refundable enforcement.',
                'Webhook/refund evidence that reconciles requested, pending, succeeded and failed states; an API 2xx is not success by itself.',
            ),
        );
        if ($obligationId < 1) {
            $out['reasons'][] = 'obligation_required';
            return $out;
        }
        $settlement = $wpdb->get_row($wpdb->prepare(
            "SELECT settlement.*,evidence.provider_key,evidence.provider_account_digest,evidence.processing_state
             FROM {$this->p}commercial_obligation_settlements settlement
             INNER JOIN {$this->p}commercial_payment_evidence evidence ON evidence.id=settlement.evidence_id
             WHERE settlement.obligation_id=%d LIMIT 1",
            $obligationId
        ));
        if (!$settlement) {
            $out['reasons'][] = 'settlement_required';
            return $out;
        }
        $out['provider'] = (string)$settlement->provider_key;
        $out['remaining_minor'] = (new ManualRefundService())->remainingMinor($obligationId);
        $out['remaining_currency'] = (string)$settlement->currency;
        if ((string)$settlement->provider_key !== 'stripe') {
            $out['reasons'][] = 'not_a_stripe_settlement';
            return $out;
        }
        if ((string)$settlement->processing_state !== 'accepted') {
            $out['reasons'][] = 'settlement_evidence_not_accepted';
            return $out;
        }
        if ($out['remaining_minor'] < 1) {
            $out['reasons'][] = 'nothing_refundable';
            return $out;
        }
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT session.*
             FROM {$this->p}checkout_sessions session
             WHERE session.obligation_id=%d
             ORDER BY session.id DESC LIMIT 1",
            $obligationId
        ));
        if (!$session) {
            $out['reasons'][] = 'no_checkout_session_reference';
            return $out;
        }
        $secret = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->p}provider_projection_secrets WHERE mapping_kind='checkout_session' AND mapping_id=%d AND state='active' AND active_slot=1",
            (int)$session->id
        ));
        if (!$secret) {
            $out['reasons'][] = 'checkout_reference_not_retained';
            return $out;
        }
        try {
            $references = (new ProviderReferenceVault())->open('checkout_session', (int)$session->id, $secret);
            $reference = (string)($references['provider_object_reference'] ?? '');
            if (preg_match('/^cs_test_/D', $reference) === 1) {
                $out['mode'] = 'test';
                $out['reasons'][] = 'provider_binding_is_test_mode_only';
            } elseif (preg_match('/^cs_live_/D', $reference) === 1) {
                $out['mode'] = 'live';
                $out['reasons'][] = 'live_refund_execution_not_authorised';
            } else {
                $out['reasons'][] = 'checkout_reference_malformed';
            }
            // The retained object is a Checkout Session, not a PaymentIntent or charge.
            $out['reasons'][] = 'retained_reference_is_checkout_session_not_payment_intent_or_charge';
        } catch (\Throwable) {
            $out['reasons'][] = 'checkout_reference_cannot_be_opened';
        }
        $out['reasons'][] = 'no_verified_refund_webhook_consequence';
        $out['reasons'] = array_values(array_unique($out['reasons']));
        return $out;
    }

    /**
     * A compact assessment for the refund workspace header.
     *
     * @return array<string,mixed>
     */
    public function summary(): array {
        FinanceSupport::requireCapability(self::VIEW_CAPABILITY);
        global $wpdb;
        $stripeSettlements = (int)$wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->p}commercial_obligation_settlements settlement
             INNER JOIN {$this->p}commercial_payment_evidence evidence ON evidence.id=settlement.evidence_id
             WHERE evidence.provider_key='stripe' AND evidence.processing_state='accepted'"
        );
        return array(
            'eligible' => false,
            'stripe_settlements' => $stripeSettlements,
            'summary' => 'Integrated Stripe refund is not enabled. The retained provider reference is an encrypted test-mode Checkout Session, not a verified PaymentIntent or charge, and this release has no live refund execution authority or refund-result webhook consequence. Manual/offline refunds remain a separate recorded path.',
            'reasons' => array(
                'retained_reference_is_checkout_session_not_payment_intent_or_charge',
                'provider_binding_is_test_mode_only',
                'live_refund_execution_not_authorised',
                'no_verified_refund_webhook_consequence',
            ),
        );
    }
}
