<?php
namespace Delnavazan\Platform\Core\Application\Port;

/**
 * Provider-event boundary.
 *
 * The normaliser translates one already-authenticated provider delivery into provider-neutral facts.
 * It never authenticates a provider itself and never claims to: the caller supplies an authenticated
 * envelope that names the trusted transport which performed the cryptographic validation, the exact
 * body it validated, the authenticated instant and the transport's proof reference. A raw delivery
 * carrying only a body and headers is refused before any fact is extracted.
 *
 * It never writes Phase-O or Phase-P storage directly and never supplies a resolved canonical
 * participant or a verification assertion: the adapter supplies a provider account reference and a
 * role, and Phase P resolves identity from its own registry.
 */
interface ProviderEventNormalizer {
    /**
     * @param array{provider_code:string,transport:string,authenticated:bool,authenticated_at:string,raw_body:string,body_digest:string,proof_reference:string} $envelope
     */
    public function verify(array $envelope):bool;

    /**
     * @param array{provider_code:string,transport:string,authenticated_at:string,raw_body:string,body_digest:string,proof_reference_digest:string} $envelope
     * @return array{provider_code:string,provider_event_key:string,provider_payload_key:string,participant_role:string,provider_account_key:string,observed_at:string,join_at_utc:?string,leave_at_utc:?string,lesson_id:?int,schedule_version_id:?int,provenance_reference:string,evidence_reference:string}
     */
    public function normalise(array $envelope):array;
}
