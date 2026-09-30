<?php
namespace Delnavazan\Platform\Core\Application;

/**
 * §8.2/§13 — the verified, normalised delivery-fact intake. S registers no real binding.
 *
 * A fact arrives already normalised and verified: S stores its keyed digests, the normalised vocabulary
 * and its rank, and never the provider's raw payload. A fact that would regress state or arrive out of
 * order is retained with `applied = 0` rather than applied.
 */
interface NotificationDeliveryIntakePort {
    /**
     * @param array $normalisedFacts `notification_id`, `attempt_id`, `delivery_state`,
     *        `provider_fact_digest`, `provider_event_reference_digest`, `occurred_at`.
     */
    public function submit(array $normalisedFacts):void;
}
