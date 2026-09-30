<?php
namespace Delnavazan\Platform\Core\Application\Port;

/**
 * Consent lifecycle boundary for one provider configuration.
 *
 * Core depends on this interface, never on a provider client. An implementation may build an
 * authorization URI, exchange a code, refresh, inspect, or request revocation — it may never decide a
 * Teacher, a Lesson or a capability, and it never returns a value that becomes canonical truth.
 */
interface ProviderOAuthPort {
    /**
     * @param array{client_reference:string,redirect_uri:string,scope_snapshot:string,state:string,code_challenge:string,teacher_id:int} $request
     */
    public function authorizationUri(array $request):string;

    /**
     * Exchange one authorization code. Returns the granted scope set, the raw provider subject
     * reference and the renewable credential material. Nothing here is stored directly: the
     * authority digests the subject and seals the credential material.
     *
     * @param array{client_reference:string,redirect_uri:string,code:string,code_verifier:string} $request
     * @return array{granted_scope_snapshot:string,provider_subject_reference:string,credential_material:string,consent_version:string}
     */
    public function exchange(array $request):array;

    /**
     * @param array{credential_material:string,scope_snapshot:string} $connection
     * @return array{credential_material:string,access_valid_until_utc:string}
     */
    public function refresh(array $connection):array;

    /**
     * @param array{credential_material:string} $connection
     * @return array{revoked:bool,failure_reason_code:?string}
     */
    public function revoke(array $connection):array;

    /**
     * @param array{credential_material:string,scope_snapshot:string} $connection
     * @return array{usable:bool,provider_subject_reference:?string,granted_scope_snapshot:?string,failure_reason_code:?string}
     */
    public function inspect(array $connection):array;
}
