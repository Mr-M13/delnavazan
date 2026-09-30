<?php
namespace Delnavazan\Platform\Core\Application\PaymentExecution;

/**
 * Locked Phase 2A.2-T payment-execution vocabulary (contract §5.2).
 *
 * This class owns no authority. It states the controlled vocabulary, the structural constants and the
 * derived invariants the provider-neutral execution seam, its adapters, the mapping registry, the
 * secret vault and the provider-event intake all have to agree on. Every member is a locked code
 * constant: a vocabulary addition is a reviewable code change, never configuration and never a
 * `dzn_commercial_policies` row.
 */
final class PaymentExecutionRule {
    public const RULE_VERSION='payment_execution_v1';
    public const DOMAIN='payment_execution_v1';

    /** Controlled provider vocabulary. A provider is a key, never a class name in Core. */
    public const PROVIDERS=array('stripe');
    /** Controlled account modes. `live` exists as recorded data only; it is never reachable here. */
    public const MODES=array('test','live');
    public const ACCOUNT_STATES=array('active','suspended','closed');
    public const EXECUTION_STATES=array('disabled','enabled');
    public const CREDENTIAL_STATES=array('unconfigured','configured','invalid');

    public const OBJECT_KINDS=array('customer','payment_method','intent','charge','subscription','mandate');
    public const CANONICAL_KINDS=array('student','purchase','obligation','collection_intent','recurring_enrolment');
    public const OBJECT_STATES=array('linked','superseded','detached');

    /** Execution commands this phase can record. */
    public const OPERATIONS=array('submit_collection','cancel_collection','reconcile_collection');
    public const COMMAND_STATES=array('authorised','dispatching','completed','refused','conflicted');
    /** Durable per-command dispatch-claim lifecycle (the phase's own mutable execution row). */
    public const DISPATCH_STATES=array('claimed','in_flight','settled','released');
    /** Terminal results a `payment_execution_results` row may record; `authorised` is never stored. */
    public const RESULT_STATES=array('completed','refused','conflicted');
    public const COMMAND_CONFLICT_REASON='duplicate_command_key_materially_different_payload';
    /** Provider-neutral attempt outcomes: never a provider's own status string. */
    public const OUTCOME_STATES=array('accepted_by_provider','declined','requires_action','unavailable','invalid_request','not_attempted');
    public const ATTEMPT_REASONS=array(
        'provider_accepted','provider_declined','provider_requires_action','provider_unavailable',
        'payment_execution_not_authorised','provider_account_inactive','provider_execution_disabled',
        'provider_credentials_unconfigured','live_execution_not_authorised','collection_intent_not_submitted',
        'obligation_already_settled','collection_kind_conflict','charge_time_not_due',
        'provider_request_rejected','provider_response_unusable',
        'dispatch_in_flight','provider_reconciled',
        'dispatch_descriptor_unavailable','dispatch_descriptor_incomplete',
    );

    /** Normalised provider event vocabulary: the only event types that may reach authority. */
    public const EVENT_TYPES=array(
        'payment_succeeded','payment_failed','payment_requires_action','refund_recorded',
        'mandate_recorded','provider_recurring_semantics_unresolved','unrecognised_provider_event',
    );
    public const DECISION_STATES=array('translated','ignored','refused','conflicted');
    public const DECISION_REASONS=array(
        'evidence_submitted','obligation_not_yet_accepted','stale_provider_event','duplicate_provider_event',
        'unmapped_provider_account','unmapped_provider_object','ambiguous_obligation_attribution',
        'provider_recurring_semantics_unresolved','unrecognised_provider_event',
        'conflicting_provider_event','payment_worker_principal_required','provider_event_not_authoritative',
    );
    public const SECRET_CLASSES=array('webhook_signing_secret','api_key');
    public const CIPHER='sodium_secretbox_v1';
    /** The only providers whose secrets this build may store. Empty: no credential is writable. */
    public const PROVISIONABLE_PROVIDERS=array();
    /** Secret-vault audit vocabulary: one audit row per write, rotation, retire or reveal failure. */
    public const SECRET_AUDIT_TYPES=array('stored','rotated','retired','revoked','write_refused','decrypt_failed');
    public const SECRET_REASONS=array(
        'provider_secret_write_not_authorised','payment_secret_unavailable','unknown_cipher_version',
        'unknown_key_version','secret_scope_mismatch','test_vault_override_active',
    );
    /** Webhook verification outcome and the controlled receipt reason codes of §9.2/§9.4. */
    public const VERIFICATION_STATES=array('verified','refused');
    public const WEBHOOK_REASONS=array(
        'signature_verified','signature_invalid','signature_outside_tolerance','webhook_secret_unconfigured',
        'unsupported_signature_scheme','webhook_account_unresolved','provider_account_inactive',
        'unsupported_payment_provider','method_not_allowed','https_required','unsupported_content_type',
        'payload_too_large','empty_payload','unexpected_request_shape',
    );
    /** The bounded R2 consequence recorded on a decision row (§10.1). */
    public const R2_CONSEQUENCE_STATES=array('not_applicable','pending','applied','refused');
    public const R2_CONSEQUENCE_REASONS=array(
        'renewal_cycle_not_collectable','collection_intent_not_submitted','obligation_not_settled',
        'accepted_payment_evidence_required',
    );
    /** [C9-1] Durable per-event decision-claim lifecycle: the phase's own mutable intake row, the
     * single owner of one event's owed decision. `claimed` is live under a lease; `settled` ends a claim
     * whose owner appended the decision; `released` ends a claim whose owner appended nothing. */
    public const DECISION_CLAIM_STATES=array('claimed','settled','released');

    /** Structural constants: never configurable. */
    public const SIGNATURE_TOLERANCE_SECONDS=300;
    public const MAX_WEBHOOK_BYTES=262144;
    public const TIMESTAMP_TOLERANCE_CLAMP_SECONDS=600;
    /** [C9-1]/[C9-2] Decision-claim lease: the **bounded window one owner works inside**, expressed as how
     * long it runs from its last renewal before a later delivery may take the claim over and complete the
     * event's decision. [C10-2] It bounds the work, not merely the row: the owner re-proves and renews the
     * window immediately before every R1/R2 work unit, a renewal can never resurrect an expired window, and
     * a generation whose window has closed performs no further decision or consequence work and appends
     * nothing. [C11-1] The window is proved and renewed *inside* the unit's own transaction as well, at the
     * connection's statement boundary, so the claim row is held for the whole transaction the unit runs in:
     * a unit can neither be displaced mid-transaction nor commit a statement outside the window it was
     * granted. Structural, never a setting. */
    public const DECISION_CLAIM_LEASE_SECONDS=120;
    /** [C11-1] The connection statement boundary the intake fences every R1/R2 work unit at. WordPress
     * filters every statement — `$wpdb->query()`, and therefore every insert, update, delete and read that
     * goes through it — here before it runs, so one listener registered for the unit's duration sees the
     * exact statements of the unit's own transaction. Structural, never a setting. */
    public const DECISION_UNIT_FENCE_FILTER='query';
    /** [C11-1] The statements the fence never inspects and never blocks: the transaction opener, an unwind
     * and session/transaction configuration. They are not unit work, and a rollback must never be blocked
     * by a fence; every other statement — the unit transaction's own `COMMIT` included — is preceded by the
     * fenced proof of the window it runs inside. Structural, never a setting. */
    public const DECISION_UNIT_UNFENCED_STATEMENTS='^(START\s+TRANSACTION|ROLLBACK|SET\s)';
    /** [C9-1]/[C9-2] How long a delivery that cannot own the claim waits for the owner's decision before
     * it reports the event as durably still owing one. Structural, never a setting. */
    public const DECISION_CLAIM_WAIT_MILLISECONDS=1000;
    /** Dispatch-claim lease: how long one owner may hold an `in_flight` claim before a re-drive may
     * take it over and reconcile instead of re-issuing. Structural, never a setting. */
    public const DISPATCH_LEASE_SECONDS=120;
    /** The structured outbound call timeout every adapter must use, and the margin that must remain
     * between that call and the lease it runs under. Together they make lease expiry provably
     * unreachable while an owner is still inside a call. Structural, never a setting. */
    public const DISPATCH_CALL_TIMEOUT_SECONDS=30;
    public const DISPATCH_LEASE_MARGIN_SECONDS=60;
    /** Sealed provider dispatch descriptor: the domain-separated key-derivation domain and the locked
     * field set an adapter may seal. Nothing outside this set can be sealed, so the envelope is
     * structurally incapable of becoming a general-purpose secret or credential store. */
    public const DISPATCH_DESCRIPTOR_DOMAIN='payment_dispatch_descriptor_v1';
    public const DISPATCH_DESCRIPTOR_FIELDS=array(
        'provider_account_reference','provider_object_references','operation','provider_key','mode',
        'student_id','purchase_id','obligation_id','collection_intent_id','renewal_cycle_id',
        'amount_minor','currency','idempotency_key','sealed_at',
        'command_key_digest','idempotency_key_digest',
    );
    /** The only two outcomes of the non-mutating, pre-call descriptor preflight of §5.3/§8.3. */
    public const DESCRIPTOR_PREFLIGHT_STATES=array('ok','dispatch_descriptor_unavailable');
    /** Reserved for an explicitly authorised later slice; empty in this phase. */
    public const LIVE_EXECUTION_PROVIDERS=array();

    /**
     * The canonical references each operation requires, and the provider object kind that carries it.
     *
     * Core and every adapter agree on this locked map: a caller can never introduce a provider
     * reference the mapping registry does not already own (T-D8), and the sealed descriptor carries
     * exactly the references the operation needs and no more.
     */
    public const OPERATION_REFERENCES=array(
        'submit_collection'=>array('student'=>'customer','obligation'=>'intent','collection_intent'=>'intent'),
        'cancel_collection'=>array('student'=>'customer','obligation'=>'intent','collection_intent'=>'intent'),
        'reconcile_collection'=>array('student'=>'customer','obligation'=>'intent','collection_intent'=>'intent'),
    );
    /** The canonical kinds (and their provider object kinds) one operation needs. */
    public static function operationReferences(string $operation):array{
        $references=self::OPERATION_REFERENCES[$operation]??null;
        if($references===null)throw new \InvalidArgumentException('Controlled payment execution operation required');
        return $references;
    }
    /** The subject a command of this operation arbitrates: one intent when it owns one, else one obligation. */
    public static function arbitrationSubject(string $operation,?int $collectionIntentId,int $obligationId):array{
        self::operation($operation);
        if($collectionIntentId!==null&&$collectionIntentId>0)return array('collection_intent',$collectionIntentId);
        return array('obligation',$obligationId);
    }

    /** The proxy-header set that may be trusted only when the site is configured behind a proxy. */
    public const HTTPS_PROXY_HEADERS=array('HTTP_X_FORWARDED_PROTO');
    /** [C9-3] The operator-provisioned option that declares which proxy header this site trusts. It
     * holds header names, never authority: only a member of `HTTPS_PROXY_HEADERS` is ever honoured, and
     * an unset, empty, malformed or non-member configuration trusts no header at all, so no client can
     * mark its own delivery secure. */
    public const TRUSTED_PROXY_OPTION='dzn_platform_payment_trusted_proxy';
    /** [C9-3] The only proxy-header value that marks a delivery as client-facing TLS. */
    public const TRUSTED_PROXY_HTTPS_VALUE='https';

    /**
     * [C9-3] The proxy headers this site is configured to trust — always a subset of the locked
     * `HTTPS_PROXY_HEADERS` allowlist, never a header named by a request.
     *
     * The operator option is the configuration signal of §9.2 ("only when the site is configured behind
     * a proxy"); every name it may contribute is intersected with the allowlist, so an unconfigured site,
     * an empty value, a malformed value and a name outside the allowlist all trust nothing at all.
     */
    public static function trustedProxyHeaders():array{
        if(!function_exists('get_option'))return array();
        $configured=trim((string)get_option(self::TRUSTED_PROXY_OPTION,''));
        if($configured==='')return array();
        $trusted=array();
        // A name may be written in either the server form (`HTTP_X_FORWARDED_PROTO`) or the header form
        // (`X-Forwarded-Proto`), because both spellings name the same request header; what is *never*
        // tolerated is a name outside the allowlist.
        foreach(preg_split('/[^A-Za-z0-9_-]+/',$configured,-1,PREG_SPLIT_NO_EMPTY) as $name){
            $name=strtoupper(str_replace('-','_',$name));
            if(!str_starts_with($name,'HTTP_'))$name='HTTP_'.$name;
            if(in_array($name,self::HTTPS_PROXY_HEADERS,true))$trusted[$name]=true;
        }
        return array_keys($trusted);
    }

    /**
     * [C9-3] One *trusted* proxy header's verdict on the client-facing scheme.
     *
     * The header name must be trusted by configuration and a member of the locked allowlist; the value
     * is read as the provider-facing convention does — a comma-separated chain whose leftmost element is
     * the original client scheme — and only a leftmost `https` marks the delivery secure. No other value,
     * and no untrusted header whatever its value, may do so.
     */
    public static function proxyHeaderIndicatesHttps(string $headerName,?string $value):bool{
        $headerName=strtoupper(trim($headerName));
        if(!in_array($headerName,self::trustedProxyHeaders(),true))return false;
        $chain=explode(',',trim((string)$value));
        return strtolower(trim((string)($chain[0]??'')))===self::TRUSTED_PROXY_HTTPS_VALUE;
    }

    public static function provider(string $providerKey):?string{
        $providerKey=strtolower(trim($providerKey));
        return in_array($providerKey,self::PROVIDERS,true)?$providerKey:null;
    }
    public static function mode(string $mode):?string{
        $mode=strtolower(trim($mode));
        return in_array($mode,self::MODES,true)?$mode:null;
    }
    public static function operation(string $operation):?string{
        return in_array($operation,self::OPERATIONS,true)?$operation:null;
    }
    public static function member(string $value,array $vocabulary):bool{return in_array($value,$vocabulary,true);}
    public static function provisionableProvider(string $providerKey):bool{
        return in_array(strtolower(trim($providerKey)),self::PROVISIONABLE_PROVIDERS,true);
    }
    public static function liveExecutionAuthorised(string $providerKey):bool{
        return in_array(strtolower(trim($providerKey)),self::LIVE_EXECUTION_PROVIDERS,true);
    }
    /** The structural timeout inequality: a call can never outlive the lease it runs under. */
    public static function callFitsWithinLease():bool{
        return self::DISPATCH_CALL_TIMEOUT_SECONDS+self::DISPATCH_LEASE_MARGIN_SECONDS<=self::DISPATCH_LEASE_SECONDS;
    }
    /** `released` is reachable from `claimed` (pre-lease refusal) and from a generation-1 `in_flight`
     * no-call abort; a takeover generation is never released. */
    public static function dispatchTransition(string $from,string $to):bool{
        return in_array($from.'|'.$to,array('claimed|in_flight','claimed|released','in_flight|settled','in_flight|released'),true);
    }
    public static function terminalDispatchState(string $state):bool{return in_array($state,array('settled','released'),true);}
    public static function acceptedOutcome(string $outcomeState):bool{return $outcomeState==='accepted_by_provider';}
    /** Refusals that belong to the sealed dispatch descriptor of §6.4. */
    public static function descriptorReason(string $reason):bool{
        return in_array($reason,array('dispatch_descriptor_incomplete','dispatch_descriptor_unavailable'),true);
    }
}
