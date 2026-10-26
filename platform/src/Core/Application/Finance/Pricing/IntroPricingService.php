<?php
namespace Delnavazan\Platform\Core\Application\Finance\Pricing;

/** Resolves the authoritative free/paid introductory price for one booking request. */
final class IntroPricingService {
    public function __construct(private ?RegionalPricingService $regional = null) {
        $this->regional ??= new RegionalPricingService();
    }

    /**
     * @return array{configured:bool,mode:string,amount_minor:int,currency:?string,region_code:?string}
     */
    public function forBooking(int $bookingRequestId): array {
        if ($bookingRequestId < 1) throw new \InvalidArgumentException('intro_booking_required');
        global $wpdb;
        $p = $wpdb->prefix . 'dzn_';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT request.id,contact.country FROM {$p}booking_requests request
             INNER JOIN {$p}booking_request_contact_snapshots contact ON contact.booking_request_id=request.id AND contact.snapshot_sequence=1
             WHERE request.id=%d AND request.privacy_erased_at IS NULL",
            $bookingRequestId
        ));
        if (!$row) throw new \InvalidArgumentException('intro_booking_required');
        $country = strtoupper(trim((string) ($row->country ?? '')));
        $region = RegionalPricingRule::COUNTRY_TO_REGION[$country] ?? null;
        if ($region === null) {
            $active = $this->regional->activeAll();
            if (count($active) === 0) {
                return array('configured' => false, 'mode' => RegionalPricingRule::INTRO_FREE, 'amount_minor' => 0, 'currency' => null, 'region_code' => null);
            }
            if (count($active) !== 1) throw new \InvalidArgumentException('intro_price_region_unresolved');
            $region = (string) $active[0]['region_code'];
        }
        $authority = $this->regional->activeForRegion($region);
        if (!$authority) {
            return array('configured' => false, 'mode' => RegionalPricingRule::INTRO_FREE, 'amount_minor' => 0, 'currency' => null, 'region_code' => $region);
        }
        $mode = (string) $authority['introductory_mode'];
        $amount = RegionalPricingRule::introductoryAmountMinor($mode, $authority['introductory_amount_minor'] === null ? null : (int) $authority['introductory_amount_minor']);
        if ($mode === RegionalPricingRule::INTRO_PAID && $amount < 1) throw new \RuntimeException('intro_price_not_configured');
        return array(
            'configured' => true, 'mode' => $mode, 'amount_minor' => $amount,
            'currency' => (string) $authority['currency'], 'region_code' => $region,
        );
    }
}
