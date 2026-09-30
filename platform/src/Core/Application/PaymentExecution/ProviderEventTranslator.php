<?php
namespace Delnavazan\Platform\Core\Application\PaymentExecution;

/**
 * Provider-event translation boundary (contract §5.3, §9.4, §10).
 *
 * The translator verifies the exact raw request against one resolved account/mode scope and translates
 * a verified request into zero or more normalised envelopes. It writes no commercial storage: the
 * intake service submits its facts to the existing R1 evidence boundary.
 */
interface ProviderEventTranslator {
    public function key():string;
    /**
     * Verifies the exact raw request bytes against one resolved account/mode scope.
     *
     * @param array<string,string> $headers
     */
    public function verify(string $rawBody,array $headers,ProviderVerificationContext $context):SignatureVerdict;
    /**
     * Translates a verified request into zero or more normalised envelopes. Verifies nothing itself.
     *
     * @param array<string,string> $headers
     * @return array<int,ProviderEventEnvelope>
     */
    public function translate(string $rawBody,array $headers,ProviderVerificationContext $context,string $receivedAt):array;
}
