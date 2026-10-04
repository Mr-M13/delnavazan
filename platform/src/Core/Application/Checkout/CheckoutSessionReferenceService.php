<?php
namespace Delnavazan\Platform\Core\Application\Checkout;

use Delnavazan\Platform\Core\Application\ProviderReferenceVault;
use Delnavazan\Platform\Core\Infrastructure\Repository\ProviderIntegrationRepository;
use Delnavazan\Platform\Core\Support\Identifier;

/** Stores the minimum reversible Stripe Checkout references, encrypted at rest. */
final class CheckoutSessionReferenceService {
    private const KIND = 'checkout_session';

    public function __construct(
        private ?ProviderIntegrationRepository $repository = null,
        private ?ProviderReferenceVault $vault = null
    ) {
        $this->repository ??= new ProviderIntegrationRepository();
        $this->vault ??= new ProviderReferenceVault();
    }

    public function record(int $checkoutSessionId, string $providerReference, string $checkoutUri, int $actor, string $now): void {
        if ($checkoutSessionId < 1 || $actor < 1 || preg_match('/^cs_test_[A-Za-z0-9]+$/D', $providerReference) !== 1) {
            throw new \InvalidArgumentException('checkout_reference_invalid');
        }
        $sealed = $this->vault->seal(self::KIND, $checkoutSessionId, array(
            'provider_object_reference' => $providerReference,
            'checkout_uri_reference' => $checkoutUri,
        ));
        $existing = $this->repository->projectionSecret(self::KIND, $checkoutSessionId, true);
        if ($existing) {
            $references = $this->vault->open(self::KIND, $checkoutSessionId, $existing);
            if (!hash_equals((string) ($references['provider_object_reference'] ?? ''), $providerReference)
                || !hash_equals((string) ($references['checkout_uri_reference'] ?? ''), $checkoutUri)) {
                throw new \RuntimeException('checkout_reference_conflict');
            }
            return;
        }
        $this->repository->insertProjectionSecret(array_merge(array(
            'uid' => Identifier::uid(), 'mapping_kind' => self::KIND, 'mapping_id' => $checkoutSessionId,
            'state' => 'active', 'active_slot' => 1, 'created_at' => $now, 'created_by' => $actor,
            'retired_at' => null, 'retired_by' => null,
        ), $sealed));
    }

    public function references(int $checkoutSessionId): ?array {
        if ($checkoutSessionId < 1) throw new \InvalidArgumentException('checkout_reference_invalid');
        $row = $this->repository->projectionSecret(self::KIND, $checkoutSessionId);
        if (!$row || (string) ($row->state ?? '') !== 'active') return null;
        $references = $this->vault->open(self::KIND, $checkoutSessionId, $row);
        if (preg_match('/^cs_test_[A-Za-z0-9]+$/D', (string) ($references['provider_object_reference'] ?? '')) !== 1
            || !isset($references['checkout_uri_reference'])) {
            throw new \RuntimeException('checkout_reference_malformed');
        }
        return $references;
    }
}
